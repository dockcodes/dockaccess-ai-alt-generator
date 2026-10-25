<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Image\FileName;
use Dock\AltGenerator\Image\GdReencoder;
use Dock\AltGenerator\Image\ImageFormat;
use Dock\AltGenerator\Image\ImageInspector;
use Dock\AltGenerator\Image\ImagePolicy;
use Dock\AltGenerator\Image\ImageSanitizer;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Image\PreparedImage;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension gd
 */
final class ImageSanitizerTest extends TestCase
{
    public function testReencodingKeepsOnlyPixels(): void
    {
        $sanitizer = new ImageSanitizer(ImagePolicy::secure());

        foreach ([Images::jpegWithMetadata(), Images::pngWithMetadata(), Images::gifWithMetadata(), Images::webpWithMetadata()] as $bytes) {
            $prepared = $sanitizer->prepare(ImageSource::fromBytes($bytes . 'TRAILING-' . Images::SECRET, 'photo.jpg'));

            self::assertSame(PreparedImage::REENCODED, $prepared->method());
            self::assertStringNotContainsString(Images::SECRET, $prepared->bytes());
            self::assertStringNotContainsString('TRAILING', $prepared->bytes());
            self::assertStringNotContainsString('Exif', $prepared->bytes());
            self::assertNotFalse(@imagecreatefromstring($prepared->bytes()));
        }
    }

    public function testOpaqueImagesBecomeJpegAndTransparentOnesKeepTheirAlpha(): void
    {
        $sanitizer = new ImageSanitizer(ImagePolicy::secure());

        self::assertSame(ImageFormat::JPEG, $sanitizer->prepare(ImageSource::fromBytes(Images::png(), 'x.png'))->format());

        $transparent = $sanitizer->prepare(ImageSource::fromBytes(Images::png(64, 48, true), 'x.png'));
        self::assertContains($transparent->format(), [ImageFormat::WEBP, ImageFormat::PNG]);
        self::assertTrue((new ImageInspector(ImagePolicy::secure()))->inspect($transparent->bytes())->hasAlpha);
    }

    public function testLargeImagesAreScaledDown(): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure()->withMaxEdge(256)))->prepare(ImageSource::fromBytes(Images::jpeg(1200, 600), 'big.jpg'));

        self::assertSame([256, 128], [$prepared->width(), $prepared->height()]);
    }

    public function testTheExifOrientationIsAppliedToThePixels(): void
    {
        // Stored 64 x 48, orientation 6: shown rotated by 90 degrees, so 48 x 64.
        $prepared = (new ImageSanitizer(ImagePolicy::secure()))->prepare(ImageSource::fromBytes(Images::jpegWithMetadata(6, 64, 48), 'x.jpg'));

        self::assertSame([48, 64], [$prepared->width(), $prepared->height()]);
    }

    /**
     * @dataProvider stripped
     */
    public function testStrippingRemovesMetadataWithoutDecoding(string $bytes, string $format): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_STRIP)))->prepare(ImageSource::fromBytes($bytes . "\n" . Images::SECRET, 'x'));

        self::assertSame(PreparedImage::STRIPPED, $prepared->method());
        self::assertSame($format, $prepared->format());
        self::assertStringNotContainsString(Images::SECRET, $prepared->bytes());
        self::assertNotFalse(@imagecreatefromstring($prepared->bytes()), 'The stripped image no longer decodes.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function stripped(): iterable
    {
        yield 'JPEG' => [Images::jpegWithMetadata(), ImageFormat::JPEG];
        yield 'PNG' => [Images::pngWithMetadata(), ImageFormat::PNG];
        yield 'GIF' => [Images::gifWithMetadata(), ImageFormat::GIF];
        yield 'WebP' => [Images::webpWithMetadata(), ImageFormat::WEBP];
        yield 'JPEG with a JFIF thumbnail' => [Images::jpegWithThumbnail(), ImageFormat::JPEG];
        yield 'PNG with an oversized gAMA' => [Images::pngWithOversizedGama(), ImageFormat::PNG];
        yield 'GIF with a long control block' => [Images::gifWithLongControlBlock(), ImageFormat::GIF];
    }

    public function testStrippingKeepsTheFieldsOfJfifAndAdobeSegments(): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_STRIP)))->prepare(ImageSource::fromBytes(Images::jpegWithThumbnail(), 'x.jpg'));
        $bytes    = $prepared->bytes();

        self::assertSame("\xFF\xD8\xFF\xE0\x00\x10JFIF\0\x01\x02\x01\x00\x48\x00\x48\x00\x00", substr($bytes, 0, 20));
        self::assertSame("\xFF\xEE\x00\x0EAdobe\x00\x64\x00\x00\x00\x00\x01", substr($bytes, 20, 16));
    }

    public function testStrippingKeepsTheOrientationAndNothingElseOfExif(): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_STRIP)))->prepare(ImageSource::fromBytes(Images::jpegWithMetadata(8), 'x.jpg'));
        $info     = (new ImageInspector(ImagePolicy::secure()))->inspect($prepared->bytes());

        self::assertSame(8, $info->orientation);
        self::assertStringNotContainsString(Images::SECRET, $prepared->bytes());
    }

    public function testStrippedWebpDropsItsMetadataFlags(): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_STRIP)))->prepare(ImageSource::fromBytes(Images::webpWithMetadata(), 'x.webp'));

        self::assertSame(0, ord($prepared->bytes()[20]) & 0x2C);
        self::assertStringNotContainsString('EXIF', $prepared->bytes());
        self::assertStringNotContainsString('XMP ', $prepared->bytes());
    }

    public function testWithoutMemoryForDecodingItFallsBackToStripping(): void
    {
        $prepared = (new ImageSanitizer(ImagePolicy::secure(), new GdReencoder(0)))->prepare(ImageSource::fromBytes(Images::jpegWithMetadata(), 'x.jpg'));

        self::assertSame(PreparedImage::STRIPPED, $prepared->method());
        self::assertStringNotContainsString(Images::SECRET, $prepared->bytes());
    }

    public function testAPolicyThatDemandsReencodingRefusesWhatCannotBeDecoded(): void
    {
        $this->expectException(InvalidImageException::class);

        (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_REENCODE), new GdReencoder(0)))->prepare(ImageSource::fromBytes(Images::jpeg(), 'x.jpg'));
    }

    public function testAvifIsReencodedOrRefusedButNeverSentRaw(): void
    {
        $avif = Images::avif();

        if ($avif === null) {
            self::markTestSkipped('GD cannot write AVIF here.');
        }

        $prepared = (new ImageSanitizer(ImagePolicy::secure()))->prepare(ImageSource::fromBytes($avif, 'x.avif'));
        self::assertSame(PreparedImage::REENCODED, $prepared->method());
        self::assertSame(ImageFormat::JPEG, $prepared->format());

        try {
            (new ImageSanitizer(ImagePolicy::secure()->withMode(ImagePolicy::MODE_STRIP)))->prepare(ImageSource::fromBytes($avif, 'x.avif'));
            self::fail('An AVIF was sent without being re-encoded.');
        } catch (InvalidImageException $e) {
            self::assertSame(InvalidImageException::CANNOT_SANITIZE, $e->getReason());
        }
    }

    public function testHostileFilesNeverReachTheSanitizer(): void
    {
        $this->expectException(InvalidImageException::class);

        (new ImageSanitizer(ImagePolicy::secure()))->prepare(ImageSource::fromBytes(Images::jpegWithPhpComment(), 'x.jpg'));
    }

    /**
     * @dataProvider names
     */
    public function testUploadNamesAreHarmless(string $name, string $format, string $expected): void
    {
        self::assertSame($expected, FileName::forUpload($name, $format));
    }

    /**
     * @return iterable<array{string, string, string}>
     */
    public function names(): iterable
    {
        yield ['shell.php.png', ImageFormat::PNG, 'shell-php.png'];
        yield ['photo.jpg', ImageFormat::PNG, 'photo.png'];
        yield ['../../etc/passwd', ImageFormat::JPEG, 'passwd.jpg'];
        yield ["evil\"; filename=\"x.php\r\n", ImageFormat::JPEG, 'evil-filename-x.jpg'];
        yield ['Zdjęcie żółwia.HEIC', ImageFormat::JPEG, 'Zdj-cie-wia.jpg'];
        yield ['.htaccess', ImageFormat::GIF, 'htaccess.gif'];
        yield ['', ImageFormat::WEBP, 'image.webp'];
    }
}
