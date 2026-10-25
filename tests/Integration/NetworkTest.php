<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Integration;

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\AuthenticationException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * Against the real internet, so off by default: DOCKACCESS_ALT_NETWORK_TESTS=1.
 *
 * Certificate checks run against badssl.com; the API is called with a made-up key, which it refuses
 * before generating anything - nothing is created and no limit is spent.
 *
 * @requires extension curl
 * @requires extension gd
 */
final class NetworkTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DOCKACCESS_ALT_NETWORK_TESTS') !== '1') {
            self::markTestSkipped('Set DOCKACCESS_ALT_NETWORK_TESTS=1 to run tests against the network.');
        }
    }

    /**
     * @dataProvider badCertificates
     */
    public function testRefusesBadCertificates(string $host): void
    {
        $config = Config::create('NetworkTestKey0123456789')->withBaseUrl('https://' . $host)->withConnectRetries(0);

        $this->expectException(TransportException::class);

        (new AltGenerator($config))->usage();
    }

    /**
     * @return iterable<array{string}>
     */
    public function badCertificates(): iterable
    {
        yield ['expired.badssl.com'];
        yield ['self-signed.badssl.com'];
        yield ['wrong.host.badssl.com'];
        yield ['untrusted-root.badssl.com'];
    }

    public function testTheRealApiRefusesAnUnknownKey(): void
    {
        $generator = new AltGenerator(Config::create('not-a-real-key-' . bin2hex(random_bytes(8))));

        try {
            $generator->generate(ImageSource::fromBytes(Images::jpeg(), 'network-test.jpg'));
            self::fail('The API accepted a made-up key.');
        } catch (AuthenticationException $e) {
            self::assertSame(403, $e->getStatusCode());
        }

        $this->expectException(AuthenticationException::class);
        $generator->usage();
    }
}
