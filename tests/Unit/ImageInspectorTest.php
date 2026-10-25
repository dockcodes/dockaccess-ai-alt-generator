<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Image\ImageFormat;
use Dock\AltGenerator\Image\ImageInspector;
use Dock\AltGenerator\Image\ImagePolicy;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension gd
 */
final class ImageInspectorTest extends TestCase
{
    /** @var ImageInspector */
    private $inspector;

    protected function setUp(): void
    {
        $this->inspector = new ImageInspector(ImagePolicy::secure());
    }

    public function testRecognisesGenuineImages(): void
    {
        $cases = [
            [Images::jpeg(64, 48), ImageFormat::JPEG, false],
            [Images::png(64, 48), ImageFormat::PNG, false],
            [Images::png(64, 48, true), ImageFormat::PNG, true],
            [Images::gif(32, 24), ImageFormat::GIF, false],
            [Images::webp(64, 48), ImageFormat::WEBP, false],
        ];

        foreach ($cases as [$bytes, $format, $alpha]) {
            $info = $this->inspector->inspect($bytes);

            self::assertSame($format, $info->format);
            self::assertSame($alpha, $info->hasAlpha, $format);
            self::assertSame(strlen($bytes), $info->end, $format);
            self::assertGreaterThan(0, $info->width * $info->height);
        }

        self::assertSame([64, 48], [$this->inspector->inspect(Images::jpeg(64, 48))->width, $this->inspector->inspect(Images::jpeg(64, 48))->height]);
        self::assertSame([64, 48], [$this->inspector->inspect(Images::webp(64, 48))->width, $this->inspector->inspect(Images::webp(64, 48))->height]);
    }

    public function testRecognisesAvifWhenGdCanWriteOne(): void
    {
        $avif = Images::avif(64, 48);

        if ($avif === null) {
            self::markTestSkipped('GD cannot write AVIF here.');
        }

        $info = $this->inspector->inspect($avif);

        self::assertSame(ImageFormat::AVIF, $info->format);
        self::assertSame([64, 48], [$info->width, $info->height]);
    }

    public function testReadsTheExifOrientation(): void
    {
        self::assertSame(6, $this->inspector->inspect(Images::jpegWithMetadata(6))->orientation);
    }

    /**
     * @dataProvider hostile
     */
    public function testRefusesHostileFiles(string $bytes, string $reason): void
    {
        try {
            $this->inspector->inspect($bytes);
            self::fail('A hostile file passed.');
        } catch (InvalidImageException $e) {
            self::assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function hostile(): iterable
    {
        yield 'PHP named .png' => [Images::phpFile(), InvalidImageException::EMBEDDED_CODE];
        yield 'PHP after a PNG' => [Images::pngWithAppendedPhp(), InvalidImageException::EMBEDDED_CODE];
        yield 'PHP in a JPEG comment' => [Images::jpegWithPhpComment(), InvalidImageException::EMBEDDED_CODE];
        yield 'PHAR stub in a PNG' => [Images::pngWithPharStub(), InvalidImageException::EMBEDDED_CODE];
        yield 'SVG with a script' => [Images::svgWithScript(), InvalidImageException::UNSUPPORTED_FORMAT];
        yield 'HTML behind GIF magic' => [Images::htmlBehindGifMagic(), InvalidImageException::MALFORMED];
        yield 'ZIP' => ["PK\x03\x04" . str_repeat("\0", 40), InvalidImageException::UNSUPPORTED_FORMAT];
        yield 'PDF' => ["%PDF-1.7\n1 0 obj", InvalidImageException::UNSUPPORTED_FORMAT];
        yield 'empty' => ['', InvalidImageException::EMPTY_FILE];
        yield 'decompression bomb' => [Images::pngBomb(), InvalidImageException::BAD_DIMENSIONS];
        yield 'PNG with a broken checksum' => [Images::pngWithBrokenCrc(), InvalidImageException::MALFORMED];
        yield 'PNG cut short' => [substr(Images::png(), 0, 40), InvalidImageException::MALFORMED];
        yield 'PNG with an oversized palette' => [substr(Images::png(), 0, 33) . Images::chunk('PLTE', str_repeat("\0", 771)) . substr(Images::png(), 33), InvalidImageException::MALFORMED];
        yield 'JPEG without its end' => [Images::jpegWithoutEnd(), InvalidImageException::MALFORMED];
        yield 'JPEG magic only' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\0", InvalidImageException::MALFORMED];
        yield 'WebP lying about its size' => [Images::webpLyingAboutSize(), InvalidImageException::MALFORMED];
        yield 'GIF without a trailer' => [substr(Images::gif(), 0, -1), InvalidImageException::MALFORMED];
        yield 'ftyp without an image' => [pack('N', 16) . 'ftypavif' . "\0\0\0\0", InvalidImageException::MALFORMED];
    }

    /**
     * A real PHAR whose stub is a valid JPEG - opened through phar:// it runs, decoded as an image it
     * shows a picture. Built in a child process because phar.readonly cannot be lifted at runtime.
     */
    public function testRefusesARealJpegPharPolyglot(): void
    {
        $dir  = sys_get_temp_dir() . '/dkag-phar-' . bin2hex(random_bytes(4));
        $jpeg = $dir . '/stub.jpg';
        $phar = $dir . '/polyglot.phar';
        mkdir($dir);
        file_put_contents($jpeg, Images::jpeg());

        $script = '$p = new Phar(' . var_export($phar, true) . '); $p->startBuffering(); $p->addFromString("payload.php", "<?php echo 1;");'
            . ' $p->setStub(file_get_contents(' . var_export($jpeg, true) . ') . "<?php __HALT_COMPILER(); ?>"); $p->stopBuffering();';
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        try {
            if ($status !== 0 || !is_file($phar)) {
                self::markTestSkipped('No PHAR could be built here: ' . implode(' ', $output));
            }

            $bytes = (string) file_get_contents($phar);
            self::assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3), 'The polyglot starts as a JPEG.');

            $this->expectException(InvalidImageException::class);
            $this->inspector->inspect($bytes);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testRecordsAppendedDataInsteadOfRefusingIt(): void
    {
        $png  = Images::png();
        $info = $this->inspector->inspect($png . "PK\x03\x04appended zip");

        self::assertSame(strlen($png), $info->end);
    }

    public function testRespectsThePolicy(): void
    {
        $inspector = new ImageInspector(ImagePolicy::secure()->withFormats([ImageFormat::JPEG])->withMaxBytes(2048)->withPixelLimits(64, 4096));

        foreach ([[Images::png(), InvalidImageException::UNSUPPORTED_FORMAT], [Images::jpeg(80, 40), InvalidImageException::BAD_DIMENSIONS], [Images::jpeg(64, 64, 95) . str_repeat("\0", 4096), InvalidImageException::TOO_LARGE]] as [$bytes, $reason]) {
            try {
                $inspector->inspect($bytes);
                self::fail('The policy was not applied.');
            } catch (InvalidImageException $e) {
                self::assertSame($reason, $e->getReason());
            }
        }
    }

    public function testSurvivesRandomGarbageBehindValidMagic(): void
    {
        mt_srand(42);

        foreach (["\x89PNG\r\n\x1A\n", "\xFF\xD8\xFF", 'GIF89a', "RIFF\x20\0\0\0WEBP", pack('N', 24) . 'ftypavifavifmif1'] as $magic) {
            for ($i = 0; $i < 300; $i++) {
                $bytes = $magic;

                for ($j = 0, $n = mt_rand(0, 400); $j < $n; $j++) {
                    $bytes .= chr(mt_rand(0, 255));
                }

                try {
                    $this->inspector->inspect($bytes);
                } catch (InvalidImageException $e) {
                    // Refusing is the expected outcome; anything else (a warning, an error) fails the test.
                }
            }
        }

        $this->addToAssertionCount(1);
    }
}
