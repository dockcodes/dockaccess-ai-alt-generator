<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Connection\ConnectRequest;
use Dock\AltGenerator\Connection\Verification;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\Result\Alt;
use Dock\AltGenerator\Secret;
use Dock\AltGenerator\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ConnectionClientTest extends TestCase
{
    private const SITE_TOKEN = 'SiteToken0123456789';
    private const ACCOUNT_KEY = 'AccountKey0123456789abcdef';
    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f7081';
    private const PASSWORD = 'Hunter2 hunter2!';
    private const IMAGE_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public function testConnectsAndHandsBackTheKeys(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, [
            'success'        => true,
            'external_token' => self::ACCOUNT_KEY,
            'user'           => ['id' => 7, 'display_name' => '<b>Anna</b> Nowak'],
            'token'          => ['token' => self::SITE_TOKEN, 'alt_enabled' => true],
            'integration'    => [
                'id' => 42, 'system' => 'laravel', 'home_url' => 'https://shop.example.com',
                'secret' => self::SECRET, 'domain_verified' => true,
            ],
        ]));

        $connection = (new ConnectionClient(Config::anonymous(), $transport))->connect(
            ConnectRequest::create(self::SITE_TOKEN, 'anna@example.com', self::PASSWORD, 'Laravel', 'https://shop.example.com')
                ->withVersions('12.3.0', '1.0.0')
        );

        self::assertSame(self::SITE_TOKEN, $connection->siteToken());
        self::assertSame(self::ACCOUNT_KEY, $connection->accountKey()->reveal());
        self::assertSame(self::SECRET, $connection->secret()->reveal());
        self::assertSame(42, $connection->integrationId());
        self::assertSame('laravel', $connection->system());
        self::assertTrue($connection->isAltEnabled());
        self::assertTrue($connection->isDomainVerified());
        self::assertSame('Anna Nowak', $connection->accountName());

        $request = $transport->requests[0];
        self::assertSame('POST', $request->method());
        self::assertSame('https://dockaccess.org/fast-api/integrations/connect', $request->url());
        self::assertSame([
            'token'          => self::SITE_TOKEN,
            'login'          => 'anna@example.com',
            'password'       => self::PASSWORD,
            'system'         => 'laravel',
            'home_url'       => 'https://shop.example.com',
            'system_version' => '12.3.0',
            'module_version' => '1.0.0',
        ], json_decode((string) $request->body(), true));

        // Nothing that ends up in a log shows the keys.
        $dump = print_r($connection, true);
        self::assertStringNotContainsString(self::ACCOUNT_KEY, $dump);
        self::assertStringNotContainsString(self::SECRET, $dump);
    }

    public function testARequestDumpHidesThePassword(): void
    {
        $request = ConnectRequest::create(self::SITE_TOKEN, 'anna@example.com', self::PASSWORD, 'symfony', 'https://shop.example.com');

        self::assertStringNotContainsString(self::PASSWORD, print_r($request, true));
        self::assertArrayNotHasKey('overwrite', $request->toPayload());
        self::assertTrue($request->withOverwrite()->toPayload()['overwrite']);
    }

    /**
     * @dataProvider invalidRequests
     */
    public function testRefusesAnInvalidRequestLocally(string $token, string $login, string $password, string $system, string $url): void
    {
        $this->expectException(ConfigurationException::class);

        ConnectRequest::create($token, $login, $password, $system, $url);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public function invalidRequests(): iterable
    {
        yield 'no token' => ['', 'anna', 'pass', 'laravel', 'https://shop.example.com'];
        yield 'token with a newline' => ["abc\ndef", 'anna', 'pass', 'laravel', 'https://shop.example.com'];
        yield 'no password' => [self::SITE_TOKEN, 'anna', '', 'laravel', 'https://shop.example.com'];
        yield 'bad system' => [self::SITE_TOKEN, 'anna', 'pass', 'Laravel 12!', 'https://shop.example.com'];
        yield 'relative url' => [self::SITE_TOKEN, 'anna', 'pass', 'laravel', 'shop.example.com'];
        yield 'ftp url' => [self::SITE_TOKEN, 'anna', 'pass', 'laravel', 'ftp://shop.example.com'];
    }

    /**
     * @dataProvider refusals
     */
    public function testNamesTheReasonOfARefusal(int $status, array $body, string $reason): void
    {
        $client = new ConnectionClient(Config::anonymous(), new FakeTransport(FakeTransport::json($status, $body)));

        try {
            $client->connect(ConnectRequest::create(self::SITE_TOKEN, 'anna', self::PASSWORD, 'laravel', 'https://shop.example.com'));
            self::fail('No exception.');
        } catch (ConnectionException $e) {
            self::assertSame($reason, $e->getReason());
            self::assertSame($status, $e->getStatusCode());
            self::assertStringStartsWith('dockaccess.org refused the request', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, string}>
     */
    public function refusals(): iterable
    {
        yield 'wrong password' => [401, ['reason' => 'invalid_credentials', 'detail' => 'Invalid login or password.'], ConnectionException::INVALID_CREDENTIALS];
        yield 'not verified' => [403, ['reason' => 'email_not_verified', 'detail' => 'Confirm your e-mail.'], ConnectionException::EMAIL_NOT_VERIFIED];
        yield 'foreign token' => [403, ['reason' => 'token_not_owned'], ConnectionException::TOKEN_NOT_OWNED];
        yield 'foreign domain' => [403, ['reason' => 'domain_not_allowed'], ConnectionException::DOMAIN_NOT_ALLOWED];
        yield 'generator off' => [403, ['reason' => 'alt_disabled'], ConnectionException::ALT_DISABLED];
        yield 'paused' => [403, ['reason' => 'alt_paused'], ConnectionException::ALT_PAUSED];
        yield 'taken' => [409, ['reason' => 'already_connected', 'requires_confirmation' => true], ConnectionException::ALREADY_CONNECTED];
        yield 'lockout' => [429, ['reason' => 'too_many_attempts'], ConnectionException::TOO_MANY_ATTEMPTS];
        yield 'validation' => [400, ['detail' => ['home_url' => 'Invalid home url.']], ConnectionException::INVALID_REQUEST];
        yield 'unknown reason' => [403, ['reason' => 'something_new'], ConnectionException::REFUSED];
    }

    public function testAValidationMessageNamesTheFields(): void
    {
        $client = new ConnectionClient(Config::anonymous(), new FakeTransport(FakeTransport::json(400, ['detail' => ['home_url' => 'Invalid home url.', 'system' => 'Invalid system.']])));

        $this->expectExceptionMessage('Invalid home url. Invalid system.');
        $client->connect(ConnectRequest::create(self::SITE_TOKEN, 'anna', 'pass', 'laravel', 'https://shop.example.com'));
    }

    public function testAnAccountWithoutAKeyIsNotAConnection(): void
    {
        $client = new ConnectionClient(Config::anonymous(), new FakeTransport(FakeTransport::json(200, [
            'external_token' => null,
            'integration'    => ['id' => 1, 'secret' => self::SECRET],
        ])));

        $this->expectException(UnexpectedResponseException::class);
        $client->connect(ConnectRequest::create(self::SITE_TOKEN, 'anna', 'pass', 'laravel', 'https://shop.example.com'));
    }

    /**
     * @dataProvider verifications
     */
    public function testVerifies(array $body, string $state): void
    {
        $transport    = new FakeTransport(FakeTransport::json(200, $body));
        $verification = (new ConnectionClient(Config::anonymous(), $transport))->verify(self::SITE_TOKEN, new Secret(self::SECRET));

        self::assertSame($state, $verification->state());
        self::assertSame($state === Verification::ACTIVE, $verification->isActive());
        self::assertSame(
            ['token' => self::SITE_TOKEN, 'secret' => self::SECRET, 'usage' => false],
            json_decode((string) $transport->requests[0]->body(), true)
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public function verifications(): iterable
    {
        yield 'active' => [['alt_enabled' => true, 'token' => ['alt_enabled' => true, 'alt_paused' => false]], Verification::ACTIVE];
        yield 'paused' => [['alt_enabled' => false, 'token' => ['alt_enabled' => true, 'alt_paused' => true]], Verification::PAUSED];
        yield 'off' => [['alt_enabled' => false, 'token' => ['alt_enabled' => false, 'alt_paused' => false]], Verification::DISABLED];
        yield 'nonsense' => [['alt_enabled' => 'yes'], Verification::DISABLED];
    }

    public function testVerifyCarriesTheUsage(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, [
            'alt_enabled' => true,
            'integration' => ['id' => 42, 'system' => 'symfony'],
            'alt'         => [
                'requests' => ['limit' => 2000, 'used' => 15, 'period' => 'month', 'source' => 'api'],
                'websites' => ['limit' => 3, 'used' => 2],
            ],
        ]));

        $verification = (new ConnectionClient(Config::anonymous(), $transport))->verify(self::SITE_TOKEN, new Secret(self::SECRET), true);

        self::assertNotNull($verification->usage());
        self::assertSame([15, 2000, 'month'], [$verification->usage()->used(), $verification->usage()->limit(), $verification->usage()->period()]);
        self::assertSame([2, 3], [$verification->websitesUsed(), $verification->websitesLimit()]);
        self::assertSame([42, 'symfony'], [$verification->integrationId(), $verification->system()]);
        self::assertTrue(json_decode((string) $transport->requests[0]->body(), true)['usage']);
    }

    public function testADisconnectedSiteIsGone(): void
    {
        $client = new ConnectionClient(Config::anonymous(), new FakeTransport(FakeTransport::json(403, [
            'reason' => 'disconnected',
            'detail' => 'Connection ' . self::SECRET . ' has been disconnected.',
        ])));

        try {
            $client->verify(self::SITE_TOKEN, new Secret(self::SECRET));
            self::fail('No exception.');
        } catch (ConnectionException $e) {
            self::assertTrue($e->isConnectionGone());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testDisconnects(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['success' => true]));

        (new ConnectionClient(Config::anonymous(), $transport))->disconnect(self::SITE_TOKEN, 'anna', self::PASSWORD, 'https://shop.example.com');

        self::assertSame('https://dockaccess.org/fast-api/integrations/disconnect', $transport->requests[0]->url());
        self::assertSame('https://shop.example.com', json_decode((string) $transport->requests[0]->body(), true)['home_url']);
    }

    public function testSendsEditedAltsBack(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['success' => true]));
        $client    = new ConnectionClient(Config::create(self::ACCOUNT_KEY)->withConnectionUrl('https://staging.dockaccess.org/fast-api/'), $transport);

        $client->updateAlts(self::SITE_TOKEN, strtoupper(self::IMAGE_UUID), new Alt('<i>Rudy</i> kot', 'pl'), new Alt('  ', 'en'));

        $request = $transport->requests[0];
        self::assertSame('PUT', $request->method());
        self::assertSame('https://staging.dockaccess.org/fast-api/alts/update', $request->url());
        self::assertSame([
            'image_uuid' => self::IMAGE_UUID,
            'key'        => self::ACCOUNT_KEY,
            'token'      => self::SITE_TOKEN,
            'alts'       => [['alt' => 'Rudy kot', 'locale' => 'pl']],
        ], json_decode((string) $request->body(), true));
    }

    public function testSendingAltsBackNeedsTheKey(): void
    {
        $this->expectException(ConfigurationException::class);

        (new ConnectionClient(Config::anonymous(), new FakeTransport()))->updateAlts(self::SITE_TOKEN, self::IMAGE_UUID, new Alt('Kot', 'pl'));
    }

    public function testNothingIsRepeated(): void
    {
        $transport = new FakeTransport(new TransportException('Could not reach the alt generator: refused (cURL error 7).', true, 7));

        try {
            (new ConnectionClient(Config::anonymous()->withConnectRetries(3), $transport))
                ->connect(ConnectRequest::create(self::SITE_TOKEN, 'anna', 'pass', 'laravel', 'https://shop.example.com'));
            self::fail('No exception.');
        } catch (TransportException $e) {
            self::assertCount(1, $transport->requests);
            self::assertStringContainsString('dockaccess.org', $e->getMessage());
        }
    }

    public function testAServerFailureIsNotARefusal(): void
    {
        $this->expectException(ServerException::class);

        (new ConnectionClient(Config::anonymous(), new FakeTransport(FakeTransport::json(502, ['detail' => 'Bad gateway']))))
            ->verify(self::SITE_TOKEN, new Secret(self::SECRET));
    }
}
