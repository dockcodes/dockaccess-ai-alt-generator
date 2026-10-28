<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Connection;

use Dock\AltGenerator\Exception\ConfigurationException;

/**
 * What a site sends to connect itself to a dockaccess.org account: the site token from the account,
 * the account owner's login and password (used once, never stored by this library), and who is
 * connecting - the system shown in the account ("laravel", "symfony", "wordpress") and the address
 * of the site, which has to be one of the site's domains or a local host.
 *
 * The password is held only until the request is built; `__debugInfo()` and `var_dump()` do not
 * show it.
 */
final class ConnectRequest
{
    private const SYSTEM = '/^[a-z0-9][a-z0-9._-]{1,39}$/D';

    /** @var string */
    private $siteToken;

    /** @var string */
    private $login;

    /** @var string */
    private $password;

    /** @var string */
    private $system;

    /** @var string */
    private $homeUrl;

    /** @var string|null */
    private $systemVersion;

    /** @var string|null */
    private $moduleVersion;

    /** @var string|null */
    private $callbackUrl;

    /** @var bool */
    private $overwrite = false;

    private function __construct(string $siteToken, string $login, string $password, string $system, string $homeUrl)
    {
        $this->siteToken = $siteToken;
        $this->login     = $login;
        $this->password  = $password;
        $this->system    = $system;
        $this->homeUrl   = $homeUrl;
    }

    public static function create(
        string $siteToken,
        string $login,
        #[\SensitiveParameter]
        string $password,
        string $system,
        string $homeUrl
    ): self {
        $siteToken = trim($siteToken);
        $login     = trim($login);
        $system    = strtolower(trim($system));
        $homeUrl   = trim($homeUrl);

        if ($siteToken === '' || preg_match('/[\s\x00-\x1F\x7F]/', $siteToken) === 1 || strlen($siteToken) > 255) {
            throw new ConfigurationException('The site token is empty or not valid.');
        }

        if ($login === '' || $password === '') {
            throw new ConfigurationException('The login and the password of the dockaccess.org account are required.');
        }

        if (preg_match(self::SYSTEM, $system) !== 1) {
            throw new ConfigurationException('The system must be 2 to 40 characters from a-z, 0-9, ".", "_" and "-".');
        }

        if (!self::isHttpUrl($homeUrl)) {
            throw new ConfigurationException('The site address must be an absolute http(s) URL.');
        }

        return new self($siteToken, $login, $password, $system, $homeUrl);
    }

    /**
     * The framework or CMS version and the integration's own version, shown in the account.
     */
    public function withVersions(?string $systemVersion, ?string $moduleVersion): self
    {
        $copy                = clone $this;
        $copy->systemVersion = self::version($systemVersion);
        $copy->moduleVersion = self::version($moduleVersion);

        return $copy;
    }

    /**
     * Where dockaccess.org may push alts edited in the account. Optional.
     */
    public function withCallbackUrl(string $callbackUrl): self
    {
        if (!self::isHttpUrl($callbackUrl)) {
            throw new ConfigurationException('The callback address must be an absolute http(s) URL.');
        }

        $copy              = clone $this;
        $copy->callbackUrl = $callbackUrl;

        return $copy;
    }

    /**
     * Replaces a connection that already exists for this address. Without it such a request fails
     * with ConnectionException::ALREADY_CONNECTED, so a second installation cannot take over silently.
     */
    public function withOverwrite(bool $overwrite = true): self
    {
        $copy            = clone $this;
        $copy->overwrite = $overwrite;

        return $copy;
    }

    public function siteToken(): string
    {
        return $this->siteToken;
    }

    public function system(): string
    {
        return $this->system;
    }

    public function homeUrl(): string
    {
        return $this->homeUrl;
    }

    /**
     * The JSON body of `integrations/connect`.
     *
     * @return array<string, string|bool>
     */
    public function toPayload(): array
    {
        $payload = [
            'token'     => $this->siteToken,
            'login'     => $this->login,
            'password'  => $this->password,
            'system'    => $this->system,
            'home_url'  => $this->homeUrl,
        ];

        foreach (['system_version' => $this->systemVersion, 'module_version' => $this->moduleVersion, 'callback_url' => $this->callbackUrl] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        if ($this->overwrite) {
            $payload['overwrite'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'siteToken' => $this->siteToken,
            'login'     => $this->login,
            'password'  => '[redacted]',
            'system'    => $this->system,
            'homeUrl'   => $this->homeUrl,
            'overwrite' => $this->overwrite,
        ];
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'], $parts['pass']);
    }

    private static function version(?string $version): ?string
    {
        $version = trim((string) preg_replace('/[^0-9A-Za-z._\- ]/', '', (string) $version));

        return $version === '' ? null : substr($version, 0, 40);
    }
}
