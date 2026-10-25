<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension gd
 */
final class ImageSourceTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var string */
    private $uploads;

    /** @var string */
    private $outside;

    protected function setUp(): void
    {
        $this->dir     = sys_get_temp_dir() . '/dkag-' . bin2hex(random_bytes(6));
        $this->uploads = $this->dir . '/uploads';
        $this->outside = $this->dir . '/private';
        mkdir($this->uploads . '/2026', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->uploads . '/2026/cat.jpg', Images::jpeg());
        file_put_contents($this->outside . '/wp-config.php', "<?php define('DB_PASSWORD', 'secret');");
        file_put_contents($this->outside . '/photo.jpg', Images::jpeg());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testReadsAFileInsideTheAllowedDirectory(): void
    {
        $source = ImageSource::fromFile($this->uploads . '/2026/cat.jpg', [$this->uploads]);

        self::assertSame('cat.jpg', $source->name());
        self::assertSame(Images::jpeg(), $source->bytes());
    }

    /**
     * @dataProvider escapes
     */
    public function testRefusesEverythingOutsideTheAllowedDirectories(string $relative): void
    {
        $this->expectException(InvalidImageException::class);

        ImageSource::fromFile($this->uploads . $relative, [$this->uploads]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function escapes(): iterable
    {
        yield 'traversal' => ['/../private/wp-config.php'];
        yield 'traversal to an image' => ['/2026/../../private/photo.jpg'];
        yield 'missing' => ['/2026/missing.jpg'];
        yield 'directory' => ['/2026'];
    }

    public function testRefusesASymlinkThatLeadsOutside(): void
    {
        symlink($this->outside . '/wp-config.php', $this->uploads . '/2026/avatar.png');

        try {
            ImageSource::fromFile($this->uploads . '/2026/avatar.png', [$this->uploads]);
            self::fail('A symlink out of the uploads was followed.');
        } catch (InvalidImageException $e) {
            self::assertSame(InvalidImageException::NOT_ALLOWED, $e->getReason());
        }
    }

    public function testFollowsASymlinkThatStaysInside(): void
    {
        symlink($this->uploads . '/2026/cat.jpg', $this->uploads . '/latest.jpg');

        self::assertSame(Images::jpeg(), ImageSource::fromFile($this->uploads . '/latest.jpg', [$this->uploads])->bytes());
    }

    public function testADirectoryNamedLikeTheRootIsNotInsideIt(): void
    {
        mkdir($this->dir . '/uploads-evil');
        file_put_contents($this->dir . '/uploads-evil/x.jpg', Images::jpeg());

        $this->expectException(InvalidImageException::class);

        ImageSource::fromFile($this->dir . '/uploads-evil/x.jpg', [$this->uploads]);
    }

    /**
     * @dataProvider wrappers
     */
    public function testRefusesStreamWrappersAndUrls(string $path): void
    {
        try {
            ImageSource::fromFile($path, [$this->uploads]);
            self::fail('A stream wrapper was accepted.');
        } catch (InvalidImageException $e) {
            self::assertSame(InvalidImageException::NOT_ALLOWED, $e->getReason());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function wrappers(): iterable
    {
        yield 'phar' => ['phar:///tmp/x.phar/cat.jpg'];
        yield 'php filter' => ['php://filter/convert.base64-encode/resource=/etc/passwd'];
        yield 'data' => ['data:image/png;base64,iVBORw0KGgo='];
        yield 'file' => ['file:///etc/passwd'];
        yield 'http' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'glob' => ['glob:///etc/*'];
        yield 'nul byte' => ["/tmp/cat.jpg\0.png"];
        yield 'empty' => [''];
    }

    public function testNeedsAtLeastOneExistingDirectory(): void
    {
        $this->expectException(ConfigurationException::class);

        ImageSource::fromFile($this->uploads . '/2026/cat.jpg', ['/does/not/exist', 'phar:///x', '']);
    }

    public function testRefusesFilesAboveTheLimitBeforeReadingThem(): void
    {
        try {
            ImageSource::fromFile($this->uploads . '/2026/cat.jpg', [$this->uploads], 100);
            self::fail('An oversized file was read.');
        } catch (InvalidImageException $e) {
            self::assertSame(InvalidImageException::TOO_LARGE, $e->getReason());
        }
    }

    public function testRefusesEmptyFiles(): void
    {
        touch($this->uploads . '/empty.jpg');

        try {
            ImageSource::fromFile($this->uploads . '/empty.jpg', [$this->uploads]);
            self::fail('An empty file was read.');
        } catch (InvalidImageException $e) {
            self::assertSame(InvalidImageException::EMPTY_FILE, $e->getReason());
        }
    }

    public function testMessagesNeverDiscloseThePath(): void
    {
        try {
            ImageSource::fromFile($this->outside . '/wp-config.php', [$this->uploads]);
        } catch (InvalidImageException $e) {
            self::assertStringNotContainsString('wp-config', $e->getMessage());
            self::assertStringNotContainsString($this->dir, $e->getMessage());
        }
    }

    public function testUploadsMustComeFromPhp(): void
    {
        $this->expectException(InvalidImageException::class);

        ImageSource::fromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->outside . '/wp-config.php', 'name' => 'x.jpg']);
    }

    public function testBytesKeepOnlyTheBaseName(): void
    {
        self::assertSame('passwd', ImageSource::fromBytes('x', '../../../etc/passwd')->name());
        self::assertSame('cat.png', ImageSource::fromBytes('x', 'C:\\Users\\jan\\cat.png')->name());
    }
}
