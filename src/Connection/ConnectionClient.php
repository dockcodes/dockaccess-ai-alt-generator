<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Connection;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\Http\CurlTransport;
use Dock\AltGenerator\Http\Request;
use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Redactor;
use Dock\AltGenerator\Result\Alt;
use Dock\AltGenerator\Result\Usage;
use Dock\AltGenerator\Secret;
use Dock\AltGenerator\Text\PlainText;

/**
 * The site's connection to a dockaccess.org account (`/fast-api/integrations/*`), the same handshake
 * the WordPress, PrestaShop and Joomla modules use:
 *
 *     $client     = new ConnectionClient(Config::anonymous());
 *     $connection = $client->connect(ConnectRequest::create($siteToken, $login, $password, 'laravel', $appUrl));
 *     // store $connection->siteToken(), ->accountKey(), ->secret() as secrets
 *
 *     $client->verify($siteToken, $secret)->isActive();   // before generating
 *
 * A connected site appears in the account (alt generator tab of the site) with its system and the
 * time it last verified; the owner can disconnect it there, and its next verify() then fails with
 * ConnectionException::DISCONNECTED.
 *
 * The login and the password travel once, in the body of an HTTPS request that is never redirected
 * and never repeated after it may have reached the server; they are not kept.
 */
final class ConnectionClient
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    private const MAX_MESSAGE = 300;

    /** @var Config */
    private $config;

    /** @var TransportInterface */
    private $transport;

    public function __construct(Config $config, ?TransportInterface $transport = null)
    {
        $this->config    = $config;
        $this->transport = $transport ?? new CurlTransport($config);
    }

    /**
     * Connects the site and hands back the account key and the connection secret.
     *
     * @throws ConnectionException          the account refused (see its reason; TOO_MANY_ATTEMPTS after
     *                                      ten failed logins from this server in 15 minutes)
     * @throws ServerException              dockaccess.org failed
     * @throws TransportException           dockaccess.org could not be reached
     * @throws UnexpectedResponseException  the answer broke the contract
     */
    public function connect(ConnectRequest $request): Connection
    {
        $response = $this->post('/integrations/connect', $request->toPayload());
        $data     = $this->json($response, new Redactor());

        $accountKey  = $data['external_token'] ?? null;
        $integration = is_array($data['integration'] ?? null) ? $data['integration'] : [];
        $token       = is_array($data['token'] ?? null) ? $data['token'] : [];
        $user        = is_array($data['user'] ?? null) ? $data['user'] : [];
        $secret      = $integration['secret'] ?? null;

        if (!is_string($accountKey) || $accountKey === '') {
            throw new UnexpectedResponseException('The account has no generator key yet. Try again in a minute.', $response->status());
        }

        if (!is_string($secret) || !is_int($integration['id'] ?? null)) {
            throw new UnexpectedResponseException('The connection answer lacks the connection secret.', $response->status());
        }

        try {
            $accountKey = new Secret($accountKey);
            $secret     = new Secret($secret);
        } catch (ConfigurationException $e) {
            throw new UnexpectedResponseException('The connection answer carries keys in a form the client does not accept.', $response->status());
        }

        return new Connection(
            is_string($token['token'] ?? null) ? $token['token'] : $request->siteToken(),
            $accountKey,
            $secret,
            $integration['id'],
            is_string($integration['system'] ?? null) ? $integration['system'] : $request->system(),
            is_string($integration['home_url'] ?? null) ? $integration['home_url'] : $request->homeUrl(),
            ($token['alt_enabled'] ?? false) === true,
            ($integration['domain_verified'] ?? false) === true,
            PlainText::clean(is_string($user['display_name'] ?? null) ? $user['display_name'] : '', 190)
        );
    }

    /**
     * Whether the site may generate now. Cheap - no login, no password - so run it before generating
     * or cache its answer for a few minutes.
     *
     * @param bool $withUsage also ask for the generator usage (one more call on the account's side)
     *
     * @throws ConnectionException  INVALID_CREDENTIALS or DISCONNECTED: the connection is gone, connect again
     * @throws TransportException
     */
    public function verify(
        string $siteToken,
        Secret $secret,
        bool $withUsage = false
    ): Verification {
        $response = $this->post('/integrations/verify', [
            'token'  => $siteToken,
            'secret' => $secret->reveal(),
            'usage'  => $withUsage,
        ]);
        $data = $this->json($response, new Redactor($secret));

        $token       = is_array($data['token'] ?? null) ? $data['token'] : [];
        $integration = is_array($data['integration'] ?? null) ? $data['integration'] : [];
        $alt         = is_array($data['alt'] ?? null) ? $data['alt'] : [];

        if (($data['alt_enabled'] ?? false) === true) {
            $state = Verification::ACTIVE;
        } elseif (($token['alt_paused'] ?? false) === true) {
            $state = Verification::PAUSED;
        } else {
            $state = Verification::DISABLED;
        }

        $websites = is_array($alt['websites'] ?? null) ? $alt['websites'] : [];

        return new Verification(
            $state,
            is_int($integration['id'] ?? null) ? $integration['id'] : 0,
            is_string($integration['system'] ?? null) ? $integration['system'] : '',
            self::usage($alt['requests'] ?? null),
            is_int($websites['used'] ?? null) ? $websites['used'] : null,
            is_int($websites['limit'] ?? null) ? $websites['limit'] : null
        );
    }

    /**
     * Disconnects the site; it disappears from the account's list of connected integrations. A site
     * the account no longer knows fails with ConnectionException::NOT_CONNECTED - treat that as done.
     *
     * @throws ConnectionException
     * @throws TransportException
     */
    public function disconnect(
        string $siteToken,
        string $login,
        #[\SensitiveParameter]
        string $password,
        string $homeUrl
    ): void {
        $response = $this->post('/integrations/disconnect', [
            'token'    => $siteToken,
            'login'    => $login,
            'password' => $password,
            'home_url' => $homeUrl,
        ]);

        $this->json($response, new Redactor());
    }

    /**
     * Sends alts edited on the site back to the account, so the account and the site show the same
     * text. Needs the account key of a connected site (`Config::create($accountKey)`).
     *
     * @throws ConfigurationException  no account key, or an image UUID that is not one
     * @throws ConnectionException
     * @throws TransportException
     */
    public function updateAlts(string $siteToken, string $imageUuid, Alt ...$alts): void
    {
        if (preg_match(self::UUID, $imageUuid) !== 1) {
            throw new ConfigurationException('The image UUID is not valid.');
        }

        $items = [];

        foreach ($alts as $alt) {
            $text = PlainText::clean($alt->text(), $this->config->maxAltLength());

            if ($text !== '') {
                $items[] = ['alt' => $text, 'locale' => $alt->locale()];
            }
        }

        if ($items === []) {
            throw new ConfigurationException('At least one non-empty alt is required.');
        }

        $key      = $this->config->accountKey();
        $response = $this->send('PUT', '/alts/update', [
            'image_uuid' => strtolower($imageUuid),
            'key'        => $key->reveal(),
            'token'      => $siteToken,
            'alts'       => $items,
        ]);

        $this->json($response, new Redactor($key));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload): Response
    {
        return $this->send('POST', $path, $payload);
    }

    /**
     * Sends once. Unlike AltGenerator, nothing is repeated here: a connect that reached the server
     * rotates the connection secret, and a repeated failed login counts towards the lockout.
     *
     * @param array<string, mixed> $payload
     */
    private function send(string $method, string $path, array $payload): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new ConfigurationException('The request could not be encoded as JSON.');
        }

        try {
            return $this->transport->send(new Request(
                $method,
                $this->config->connectionUrl() . $path,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $body
            ));
        } catch (TransportException $e) {
            throw new TransportException(
                str_replace('the alt generator', 'dockaccess.org', $e->getMessage()),
                $e->isRetryable(),
                $e->getCode()
            );
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response, Redactor $redactor): array
    {
        $status = $response->status();
        $data   = self::decode($response->body());

        if ($status >= 200 && $status < 300) {
            if ($data === null) {
                throw new UnexpectedResponseException('dockaccess.org answered with something that is not JSON.', $status);
            }

            return $data;
        }

        $message = self::message($data, $redactor);
        $reason  = is_string($data['reason'] ?? null) ? $data['reason'] : null;

        if ($status >= 500 && $status < 600) {
            throw new ServerException($status, $message);
        }

        if ($status >= 400 && $status < 500) {
            throw new ConnectionException($status, $message, self::reason($status, $reason));
        }

        throw new UnexpectedResponseException(sprintf('dockaccess.org answered with HTTP %d.', $status), $status);
    }

    private static function reason(int $status, ?string $reason): string
    {
        $known = [
            ConnectionException::INVALID_CREDENTIALS,
            ConnectionException::EMAIL_NOT_VERIFIED,
            ConnectionException::TOKEN_NOT_OWNED,
            ConnectionException::DOMAIN_NOT_ALLOWED,
            ConnectionException::ALT_DISABLED,
            ConnectionException::ALT_PAUSED,
            ConnectionException::ALREADY_CONNECTED,
            ConnectionException::DISCONNECTED,
            ConnectionException::NOT_CONNECTED,
            ConnectionException::TOO_MANY_ATTEMPTS,
        ];

        if ($reason !== null && in_array($reason, $known, true)) {
            return $reason;
        }

        if ($status === 429) {
            return ConnectionException::TOO_MANY_ATTEMPTS;
        }

        return $status === 400 || $status === 422 ? ConnectionException::INVALID_REQUEST : ConnectionException::REFUSED;
    }

    /**
     * `detail` is a sentence, or an object of field => sentence for a request that failed validation.
     *
     * @param array<mixed>|null $data
     */
    private static function message(?array $data, Redactor $redactor): string
    {
        $detail = $data['detail'] ?? null;

        if (is_array($detail)) {
            $detail = implode(' ', array_filter($detail, 'is_string'));
        }

        if (!is_string($detail) || trim($detail) === '') {
            return 'No details given.';
        }

        $clean = PlainText::clean($redactor->redact($detail), self::MAX_MESSAGE);

        return $clean === '' ? 'No details given.' : $clean;
    }

    /**
     * @param mixed $requests
     */
    private static function usage($requests): ?Usage
    {
        if (!is_array($requests) || !is_int($requests['used'] ?? null) || !is_int($requests['limit'] ?? null)) {
            return null;
        }

        return new Usage($requests['used'], $requests['limit'], ($requests['period'] ?? null) === Usage::MONTH ? Usage::MONTH : Usage::DAY);
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $data = json_decode($body, true, 32);

        return is_array($data) ? $data : null;
    }
}
