<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\AltGeneratorException;
use Dock\AltGenerator\Exception\ApiException;
use Dock\AltGenerator\Exception\AuthenticationException;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\DailyLimitReachedException;
use Dock\AltGenerator\Exception\ImageRejectedException;
use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Exception\RateLimitedException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\GenerateOptions;
use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Tests\Support\FakeTransport;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension gd
 */
final class AltGeneratorTest extends TestCase
{
    private const KEY       = 'AccountKey0123456789abcdef';
    private const SET_UUID  = '11111111-2222-4333-8444-555555555555';
    private const IMAGE_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public function testGeneratesFromASanitizedUpload(): void
    {
        $transport = new FakeTransport($this->success([['alt' => "<b>Rudy kot</b> na parapecie\u{202E}", 'locale' => 'pl']]));
        $generator = new AltGenerator(Config::create(self::KEY), $transport);

        $result = $generator->generate(
            ImageSource::fromBytes(Images::jpegWithMetadata(), 'IMG 2041.php.jpg'),
            GenerateOptions::create()->withLanguage('pl_PL')->withContext('Sklep z ceramiką')->withSetRef('site-1')
        );

        self::assertSame('Rudy kot na parapecie', $result->alt()->text());
        self::assertSame('pl', $result->alt()->locale());
        self::assertSame(self::IMAGE_UUID, $result->imageUuid());
        self::assertSame(self::SET_UUID, $result->setUuid());

        $request = $transport->requests[0];
        self::assertSame('POST', $request->method());
        self::assertSame(
            'https://alt.dockaccess.org/api/generate?lang=pl&tone=neutral&variants=1&context=Sklep%20z%20ceramik%C4%85&set_ref=site-1&key=' . self::KEY,
            $request->url()
        );

        $body = (string) $request->body();
        self::assertStringContainsString('filename="IMG-2041-php.jpg"', $body);
        self::assertStringContainsString('Content-Type: image/jpeg', $body);
        self::assertStringContainsString($result->image()->bytes(), $body);
        self::assertStringNotContainsString(Images::SECRET, $body, 'Metadata reached the upload.');
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->headers()['Content-Type']);
    }

    public function testAHostileFileIsNeverSent(): void
    {
        $transport = new FakeTransport();
        $generator = new AltGenerator(Config::create(self::KEY), $transport);

        foreach ([Images::phpFile(), Images::pngWithAppendedPhp(), Images::svgWithScript(), Images::pngBomb()] as $bytes) {
            try {
                $generator->generate(ImageSource::fromBytes($bytes, 'avatar.png'));
                self::fail('A hostile file was accepted.');
            } catch (InvalidImageException $e) {
                // refused locally
            }
        }

        self::assertSame([], $transport->requests);
    }

    /**
     * @dataProvider refusals
     *
     * @param class-string<\Throwable> $expected
     */
    public function testMapsEveryRefusalToItsException(Response $response, string $expected, ?callable $check = null): void
    {
        $generator = new AltGenerator(Config::create(self::KEY), new FakeTransport($response));

        try {
            $generator->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
            self::fail('No exception.');
        } catch (AltGeneratorException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getTraceAsString());

            if ($check !== null) {
                $check($e);
            }
        }
    }

    /**
     * @return iterable<string, array>
     */
    public function refusals(): iterable
    {
        yield 'daily limit' => [
            FakeTransport::json(429, ['detail' => "You've reached your limit."], ['X-Alt-Reason' => 'limit_reached', 'X-Alt-Daily-Limit' => '50']),
            DailyLimitReachedException::class,
            static function (DailyLimitReachedException $e): void {
                self::assertSame(50, $e->getDailyLimit());
                self::assertNull($e->getPeriod());
            },
        ];
        yield 'monthly limit' => [
            FakeTransport::json(429, ['detail' => "You've reached your monthly limit."], ['X-Alt-Reason' => 'limit_reached', 'X-Alt-Daily-Limit' => '2000', 'X-Alt-Limit-Period' => 'month']),
            DailyLimitReachedException::class,
            static function (DailyLimitReachedException $e): void {
                self::assertSame(2000, $e->getDailyLimit());
                self::assertSame('month', $e->getPeriod());
            },
        ];
        yield 'per minute cap' => [
            FakeTransport::json(429, ['detail' => 'Too many requests'], ['X-Alt-Reason' => 'rate_limited', 'Retry-After' => '17']),
            RateLimitedException::class,
            static function (RateLimitedException $e): void {
                self::assertSame(17, $e->getRetryAfter());
            },
        ];
        yield 'cap without headers' => [FakeTransport::json(429, []), RateLimitedException::class, null];
        yield 'invalid key' => [FakeTransport::json(403, ['detail' => 'Invalid key']), AuthenticationException::class, null];
        yield 'unauthorised' => [FakeTransport::json(401, ['detail' => 'Could not validate credentials']), AuthenticationException::class, null];
        yield 'other forbidden' => [FakeTransport::json(403, ['detail' => 'reCAPTCHA failed']), ApiException::class, null];
        yield 'invalid image' => [FakeTransport::json(400, ['detail' => 'Invalid image']), ImageRejectedException::class, null];
        yield 'too large' => [FakeTransport::json(413, ['detail' => 'Image is too large']), ImageRejectedException::class, null];
        yield 'validation' => [
            FakeTransport::json(422, ['detail' => [['msg' => 'Input should be "pl" <script>', 'loc' => ['query', 'lang']]]]),
            ApiException::class,
            static function (ApiException $e): void {
                self::assertSame('Input should be "pl"', $e->getApiMessage());
            },
        ];
        yield 'server error' => [FakeTransport::json(500, ['detail' => 'Gemini generation error']), ServerException::class, null];
        yield 'bad gateway html' => [new Response(502, [], '<html>Bad gateway</html>'), ServerException::class, null];
        yield 'redirect' => [new Response(302, ['Location' => 'https://evil.example/'], ''), UnexpectedResponseException::class, null];
        yield 'not json' => [new Response(200, [], '404 page not found'), UnexpectedResponseException::class, null];
        yield 'no alts' => [FakeTransport::json(200, ['data' => ['set_uuid' => self::SET_UUID, 'image' => ['uuid' => self::IMAGE_UUID, 'alts' => []]]]), UnexpectedResponseException::class, null];
        yield 'bad uuid' => [FakeTransport::json(200, ['data' => ['set_uuid' => '../x', 'image' => ['uuid' => self::IMAGE_UUID, 'alts' => [['alt' => 'x']]]]]), UnexpectedResponseException::class, null];
        yield 'key echoed by the API' => [
            FakeTransport::json(400, ['detail' => 'Unknown key ' . self::KEY]),
            ImageRejectedException::class,
            static function (ApiException $e): void {
                self::assertStringContainsString('[redacted]', $e->getApiMessage());
            },
        ];
    }

    public function testRetriesOnlyWhatNeverReachedTheApi(): void
    {
        $transport = new FakeTransport(new TransportException('refused', true), $this->success([['alt' => 'A cat']]));
        $result    = (new AltGenerator(Config::create(self::KEY), $transport))->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));

        self::assertSame('A cat', $result->alt()->text());
        self::assertCount(2, $transport->requests);

        $transport = new FakeTransport(new TransportException('timed out after sending', false), $this->success([['alt' => 'A cat']]));

        try {
            (new AltGenerator(Config::create(self::KEY), $transport))->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
            self::fail('A request the API may have processed was repeated.');
        } catch (TransportException $e) {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testTransportMessagesAreRedacted(): void
    {
        $transport = new FakeTransport(new TransportException('failed https://alt.dockaccess.org/api/generate?key=' . self::KEY, false));

        try {
            (new AltGenerator(Config::create(self::KEY), $transport))->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
        } catch (TransportException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testReadsTheUsage(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['requests' => ['limit' => 50, 'used' => 12]]));
        $usage     = (new AltGenerator(Config::create(self::KEY), $transport))->usage();

        self::assertSame([12, 50, 38], [$usage->used(), $usage->limit(), $usage->remaining()]);
        self::assertSame('https://alt.dockaccess.org/api/user?key=' . self::KEY, $transport->requests[0]->url());
        // An API from before monthly plans counts the UTC day and does not say when it ends.
        self::assertSame('day', $usage->period());
        self::assertNull($usage->resetsAt());
    }

    public function testReadsAMonthlyUsage(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['requests' => [
            'limit' => 2000, 'used' => 1990, 'period' => 'month', 'resets_at' => '2026-11-01T00:00:00+00:00',
        ]]));
        $usage = (new AltGenerator(Config::create(self::KEY), $transport))->usage();

        self::assertTrue($usage->isMonthly());
        self::assertSame(10, $usage->remaining());
        self::assertNotNull($usage->resetsAt());
        self::assertSame('2026-11-01T00:00:00+00:00', $usage->resetsAt()->format(DATE_ATOM));
    }

    public function testIgnoresAResetMomentItCannotRead(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['requests' => [
            'limit' => 10, 'used' => 1, 'period' => 'week', 'resets_at' => 'tomorrow',
        ]]));
        $usage = (new AltGenerator(Config::create(self::KEY), $transport))->usage();

        self::assertSame('day', $usage->period());
        self::assertNull($usage->resetsAt());
    }

    public function testAnAnonymousConfigCannotGenerate(): void
    {
        $this->expectException(ConfigurationException::class);

        new AltGenerator(Config::anonymous(), new FakeTransport());
    }

    public function testAltsAreCutToTheConfiguredLength(): void
    {
        $transport = new FakeTransport($this->success([['alt' => str_repeat('słowo ', 60)]]));
        $result    = (new AltGenerator(Config::create(self::KEY)->withMaxAltLength(128), $transport))->generate(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));

        self::assertLessThanOrEqual(128, mb_strlen($result->alt()->text()));
    }

    /**
     * @param list<array<string, string>> $alts
     */
    private function success(array $alts): Response
    {
        return FakeTransport::json(200, ['data' => [
            'set_uuid' => self::SET_UUID,
            'set_ref'  => 'site-1',
            'image'    => ['uuid' => self::IMAGE_UUID, 'filename' => 'x.jpg', 'alts' => $alts],
        ]]);
    }
}
