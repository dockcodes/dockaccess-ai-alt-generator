<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Redactor;
use Dock\AltGenerator\Secret;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    private const KEY = 'Abc123SecretKeyValue0987';

    public function testRevealsTheValueOnlyOnRequest(): void
    {
        $secret = new Secret(self::KEY);

        self::assertSame(self::KEY, $secret->reveal());
        self::assertSame('[redacted]', (string) $secret);
        self::assertSame('"[redacted]"', json_encode($secret));
    }

    public function testNoDumpShowsTheValue(): void
    {
        $secret = new Secret(self::KEY);
        $config = new Config($secret);

        ob_start();
        var_dump($secret, $config);
        $dumped = (string) ob_get_clean();

        foreach ([$dumped, print_r($secret, true), print_r($config, true), var_export($secret, true), var_export($config, true), json_encode($config)] as $output) {
            self::assertStringNotContainsString(self::KEY, (string) $output);
        }
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(\LogicException::class);

        serialize(new Secret(self::KEY));
    }

    public function testExceptionTracesShowTheObjectNotTheValue(): void
    {
        $secret = new Secret(self::KEY);

        try {
            (static function (Secret $key): void {
                throw new \RuntimeException('boom');
            })($secret);
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getTraceAsString());
        }
    }

    /**
     * @dataProvider invalidKeys
     */
    public function testRefusesKeysThatCouldBreakAUrlOrAHeader(string $key): void
    {
        $this->expectException(ConfigurationException::class);

        new Secret($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'space' => ['abc defghij'];
        yield 'newline' => ["abcdefgh\r\nX-Injected: 1"];
        yield 'ampersand' => ['abcdefgh&lang=en'];
        yield 'unicode' => ['abcdefghż'];
        yield 'too long' => [str_repeat('a', 256)];
    }

    public function testRedactorRemovesEveryFormOfTheKeyAndQueryStrings(): void
    {
        $secret   = new Secret('Key.With~Chars-123');
        $redactor = new Redactor($secret);

        $text = 'key=Key.With~Chars-123 encoded=' . rawurlencode('Key.With~Chars-123') . ' url=https://alt.dockaccess.org/api/generate?lang=pl&key=abc';

        $clean = $redactor->redact($text);

        self::assertStringNotContainsString('Key.With~Chars-123', $clean);
        self::assertStringNotContainsString('lang=pl', $clean);
        self::assertStringContainsString('https://alt.dockaccess.org/api/generate?[redacted]', $clean);
    }
}
