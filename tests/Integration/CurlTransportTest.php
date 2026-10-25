<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Integration;

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\DailyLimitReachedException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\GenerateOptions;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * The real CurlTransport against a local stub served by PHP's built-in server.
 *
 * @requires extension gd
 * @requires extension curl
 */
final class CurlTransportTest extends TestCase
{
    private const KEY = 'IntegrationKey0123456789';

    /** @var resource|null */
    private static $server;

    /** @var int */
    private static $port;

    /** @var string */
    private static $log;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        self::$log  = (string) tempnam(sys_get_temp_dir(), 'dkag-stub');
        $command    = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Support/stub-server.php'];

        $env = ['STUB_LOG' => self::$log] + getenv();
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

        if (!is_resource($process)) {
            self::markTestSkipped('The built-in server cannot be started.');
        }

        self::$server = $process;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50000);
        }

        self::fail('The built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    public function testUploadsOnlyTheSanitizedImage(): void
    {
        $result = $this->generator('ok')->generate(
            ImageSource::fromBytes(Images::jpegWithMetadata() . "\n<appended>" . Images::SECRET, 'Wakacje 2026.jpeg'),
            GenerateOptions::create()->withLanguage('de-AT')->withSetRef('site-7')
        );

        $requests = $this->requests();
        self::assertCount(1, $requests);

        $request = $requests[0];
        $upload  = base64_decode($request['files']['file']['bytes'], true);

        self::assertSame('POST', $request['method']);
        self::assertSame(['lang' => 'de', 'tone' => 'neutral', 'variants' => '1', 'set_ref' => 'site-7', 'key' => self::KEY], $request['query']);
        self::assertSame('Wakacje-2026.jpg', $request['files']['file']['name']);
        self::assertSame('image/jpeg', $request['files']['file']['type']);
        self::assertSame($result->image()->bytes(), $upload);
        self::assertStringNotContainsString(Images::SECRET, (string) $upload);
        self::assertStringStartsWith('dockaccess-alt-generator-php/', $request['headers']['User-Agent']);
        self::assertSame('A test alt in de', $result->alt()->text());
    }

    public function testReadsUsage(): void
    {
        self::assertSame(47, $this->generator('ok')->usage()->remaining());
    }

    public function testNeverFollowsARedirect(): void
    {
        try {
            $this->generator('redirect')->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
            self::fail('A redirect was followed.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(302, $e->getStatusCode());
        }

        self::assertCount(1, $this->requests(), 'The image and the key went to the redirect target.');
    }

    public function testGivesUpAfterTheTimeout(): void
    {
        $start = microtime(true);

        try {
            $this->generator('slow', static function (Config $config): Config {
                return $config->withTimeouts(300, 800);
            })->usage();
            self::fail('No timeout.');
        } catch (TransportException $e) {
            self::assertFalse($e->isRetryable(), 'A request the API received must not be repeated.');
            self::assertLessThan(2.5, microtime(true) - $start);
        }
    }

    public function testRefusesOversizedAnswers(): void
    {
        foreach (['huge', 'headers'] as $route) {
            try {
                $this->generator($route)->usage();
                self::fail('An oversized answer was read: ' . $route);
            } catch (TransportException $e) {
                self::assertStringContainsString('larger than allowed', $e->getMessage());
            }
        }
    }

    public function testMapsTheDailyLimit(): void
    {
        $this->expectException(DailyLimitReachedException::class);

        $this->generator('limit')->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
    }

    public function testDoesNotFallBackToPlainHttp(): void
    {
        // The stub speaks plain HTTP; an HTTPS client must fail the handshake, not downgrade.
        $config = Config::create(self::KEY)->withBaseUrl('https://127.0.0.1:' . self::$port)->withTimeouts(500, 2000);

        try {
            (new AltGenerator($config))->usage();
            self::fail('HTTPS talked to a plain HTTP server.');
        } catch (TransportException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        self::assertSame([], $this->requests());
    }

    public function testRetriesARefusedConnection(): void
    {
        $config = Config::create(self::KEY)->allowInsecureLoopback()->withBaseUrl('http://127.0.0.1:' . self::freePort())->withConnectRetries(2);
        $start  = microtime(true);

        try {
            (new AltGenerator($config))->usage();
            self::fail('A closed port answered.');
        } catch (TransportException $e) {
            self::assertTrue($e->isRetryable());
            // Two retries with a 250 ms and 500 ms pause.
            self::assertGreaterThan(0.7, microtime(true) - $start);
        }
    }

    /**
     * @param (callable(Config): Config)|null $tune
     */
    private function generator(string $route, ?callable $tune = null): AltGenerator
    {
        $config = Config::create(self::KEY)->allowInsecureLoopback()->withBaseUrl('http://127.0.0.1:' . self::$port . '/' . $route);

        return new AltGenerator($tune === null ? $config : $tune($config));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::$log)));

        return array_values(array_map(static function (string $line): array {
            return (array) json_decode($line, true);
        }, $lines));
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
