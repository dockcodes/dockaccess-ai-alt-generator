<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const KEY = 'AccountKey0123456789';

    public function testDefaultsAreSecure(): void
    {
        $config = Config::create(self::KEY);

        self::assertSame('https://alt.dockaccess.org', $config->baseUrl());
        self::assertFalse($config->isInsecureLoopbackAllowed());
        self::assertSame(1, $config->connectRetries());
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function testRefusesUnsafeBaseUrls(string $url): void
    {
        $this->expectException(ConfigurationException::class);

        Config::create(self::KEY)->withBaseUrl($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function unsafeUrls(): iterable
    {
        yield 'plain http' => ['http://alt.dockaccess.org'];
        yield 'http loopback without opt-in' => ['http://127.0.0.1:8080'];
        yield 'credentials' => ['https://user:pass@alt.dockaccess.org'];
        yield 'query' => ['https://alt.dockaccess.org/?key=x'];
        yield 'fragment' => ['https://alt.dockaccess.org/#x'];
        yield 'ftp' => ['ftp://alt.dockaccess.org'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'relative' => ['alt.dockaccess.org'];
    }

    public function testHttpOnlyForLoopbackAfterOptIn(): void
    {
        $config = Config::create(self::KEY)->allowInsecureLoopback();

        self::assertSame('http://127.0.0.1:8080', $config->withBaseUrl('http://127.0.0.1:8080/')->baseUrl());

        $this->expectException(ConfigurationException::class);
        $config->withBaseUrl('http://example.com');
    }

    public function testDumpsHideTheKeyAndTheProxy(): void
    {
        $config = Config::create(self::KEY)->withProxy('http://user:proxypass@proxy.local:3128');

        $dump = print_r($config, true);

        self::assertStringNotContainsString(self::KEY, $dump);
        self::assertStringNotContainsString('proxypass', $dump);
    }

    public function testUserAgentSuffixIsSanitised(): void
    {
        $config = Config::create(self::KEY)->withUserAgentSuffix("wordpress/6.8\r\nX-Evil: 1");

        self::assertStringNotContainsString("\n", $config->userAgent());
        self::assertStringStartsWith('dockaccess-alt-generator-php/', $config->userAgent());
    }

    public function testTimeoutsAreBounded(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::create(self::KEY)->withTimeouts(10, 10);
    }
}
