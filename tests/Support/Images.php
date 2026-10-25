<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Support;

/**
 * Test images: genuine ones drawn with GD, the same with metadata added byte by byte, and hostile
 * files - polyglots, lying headers, bombs.
 */
final class Images
{
    /** A marker planted in metadata; it must never reach an upload. */
    public const SECRET = 'GPSLatitude=52.2297;Owner=jan.kowalski@example.test';

    public static function jpeg(int $width = 64, int $height = 48, int $quality = 90): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::paint($image, $width, $height);

        return self::capture(static function () use ($image, $quality): void {
            imagejpeg($image, null, $quality);
        });
    }

    public static function png(int $width = 64, int $height = 48, bool $alpha = false): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, (int) $clear);
            $red = imagecolorallocatealpha($image, 255, 0, 0, 30);
            imagefilledrectangle($image, 4, 4, (int) ($width / 2), (int) ($height / 2), (int) $red);
        } else {
            self::paint($image, $width, $height);
        }

        return self::capture(static function () use ($image): void {
            imagepng($image);
        });
    }

    public static function gif(int $width = 32, int $height = 24): string
    {
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 10, 120, 200);
        $red = imagecolorallocate($image, 220, 20, 20);
        imagefilledrectangle($image, 2, 2, (int) ($width / 2), (int) ($height / 2), (int) $red);

        return self::capture(static function () use ($image): void {
            imagegif($image);
        });
    }

    public static function webp(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::paint($image, $width, $height);

        return self::capture(static function () use ($image): void {
            imagewebp($image, null, 80);
        });
    }

    public static function avif(int $width = 64, int $height = 48): ?string
    {
        if (!function_exists('imageavif') || !defined('IMG_AVIF') || (imagetypes() & (int) constant('IMG_AVIF')) === 0) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);
        self::paint($image, $width, $height);

        $avif = self::capture(static function () use ($image): void {
            imageavif($image, null, 60, 8);
        });

        return $avif === '' ? null : $avif;
    }

    /* ------------------------------------------------------------- metadata */

    /**
     * A JPEG with EXIF (orientation and the secret), XMP-like APP1, an APP13 and a comment - all right
     * after SOI, as cameras and editors write them.
     */
    public static function jpegWithMetadata(int $orientation = 1, int $width = 64, int $height = 48): string
    {
        $jpeg = self::jpeg($width, $height);
        $exif = "Exif\0\0" . self::tiff($orientation, self::SECRET);
        $xmp  = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>" . self::SECRET . '</x:xmpmeta>';
        $iptc = "Photoshop 3.0\0" . self::SECRET;

        $segments = self::segment(0xE1, $exif) . self::segment(0xE1, $xmp) . self::segment(0xED, $iptc) . self::segment(0xFE, 'comment ' . self::SECRET);

        return substr($jpeg, 0, 2) . $segments . substr($jpeg, 2);
    }

    /**
     * A JPEG whose JFIF segment carries a thumbnail (the secret as RGB bytes) and whose Adobe segment
     * has data after its fields - both segments a stripper keeps.
     */
    public static function jpegWithThumbnail(): string
    {
        $jpeg  = self::jpeg();
        $thumb = str_pad(self::SECRET, 4 * 4 * 3, '#');
        $jfif  = "JFIF\0\x01\x02\x01\x00\x48\x00\x48\x04\x04" . substr($thumb, 0, 4 * 4 * 3);
        $adobe = "Adobe\x00\x64\x00\x00\x00\x00\x01" . self::SECRET;

        // GD writes its own JFIF segment right after SOI; this one replaces it.
        $rest = substr($jpeg, 2);
        if (substr($rest, 0, 2) === "\xFF\xE0") {
            $rest = substr($rest, 2 + unpack('n', substr($rest, 2, 2))[1]);
        }

        return "\xFF\xD8" . self::segment(0xE0, $jfif) . self::segment(0xEE, $adobe) . $rest;
    }

    /** A GIF whose graphic control block carries a second sub-block. */
    public static function gifWithLongControlBlock(): string
    {
        $gif    = self::gif();
        $packed = ord($gif[10]);
        $at     = 13 + (($packed & 0x80) !== 0 ? 3 * (2 << ($packed & 0x07)) : 0);
        $data   = substr(self::SECRET, 0, 200);
        $block  = "\x21\xF9\x04\x00\x00\x00\x00" . chr(strlen($data)) . $data . "\x00";

        return substr($gif, 0, $at) . $block . substr($gif, $at);
    }

    /** A PNG with a gAMA chunk far longer than its 4 bytes, the checksum valid. */
    public static function pngWithOversizedGama(): string
    {
        $png = self::png();

        return substr($png, 0, 33) . self::chunk('gAMA', pack('N', 45455) . self::SECRET) . substr($png, 33);
    }

    public static function pngWithMetadata(): string
    {
        $png  = self::png();
        $meta = self::chunk('tEXt', "Comment\0" . self::SECRET)
            . self::chunk('zTXt', "Author\0\0" . (string) gzcompress(self::SECRET))
            . self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . self::SECRET)
            . self::chunk('eXIf', self::tiff(1, self::SECRET))
            . self::chunk('prVt', self::SECRET);

        // After IHDR (8 + 25 bytes).
        return substr($png, 0, 33) . $meta . substr($png, 33);
    }

    public static function gifWithMetadata(): string
    {
        $gif = self::gif();
        // Header 6 + LSD 7 + palette: GD writes a global colour table for its palette.
        $packed = ord($gif[10]);
        $at     = 13 + (($packed & 0x80) !== 0 ? 3 * (2 << ($packed & 0x07)) : 0);
        $data   = substr(self::SECRET, 0, 200);
        $blocks = "\x21\xFE" . chr(strlen($data)) . $data . "\x00"                               // comment
            . "\x21\xFF\x0B" . 'XMP DataXMP' . chr(strlen($data)) . $data . "\x00";             // application

        return substr($gif, 0, $at) . $blocks . substr($gif, $at);
    }

    /**
     * An extended WebP (VP8X) with EXIF and XMP chunks and their flags set.
     */
    public static function webpWithMetadata(): string
    {
        $webp = self::webp();
        // Simple lossy file: "RIFF" size "WEBP" + one "VP8 " chunk.
        $vp8  = substr($webp, 12);
        $vp8x = 'VP8X' . pack('V', 10) . chr(0x08 | 0x04) . "\0\0\0" . self::u24(64 - 1) . self::u24(48 - 1);
        // RIFF chunks of odd length are followed by one padding byte.
        $pad  = strlen(self::SECRET) % 2 === 1 ? "\0" : '';
        $exif = 'EXIF' . pack('V', strlen(self::SECRET)) . self::SECRET . $pad;
        $xmp  = 'XMP ' . pack('V', strlen(self::SECRET)) . self::SECRET . $pad;
        $body = 'WEBP' . $vp8x . $vp8 . $exif . $xmp;

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /* -------------------------------------------------------------- hostile */

    public static function phpFile(): string
    {
        return "<?php system(\$_GET['c']); ?>";
    }

    public static function pngWithAppendedPhp(): string
    {
        return self::png() . "\n<?php echo file_get_contents('/etc/passwd'); ?>";
    }

    /** Valid image, PHP hidden in a comment - the classic upload bypass. */
    public static function jpegWithPhpComment(): string
    {
        $jpeg = self::jpeg();

        return substr($jpeg, 0, 2) . self::segment(0xFE, '<?PHP phpinfo(); ?>') . substr($jpeg, 2);
    }

    /** A PHAR stub hidden in a PNG text chunk. */
    public static function pngWithPharStub(): string
    {
        $png = self::png();

        return substr($png, 0, 33) . self::chunk('tEXt', "x\0__HALT_COMPILER(); ?>") . substr($png, 33);
    }

    public static function svgWithScript(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
    }

    public static function htmlBehindGifMagic(): string
    {
        return 'GIF89a<html><script>alert(1)</script></html>';
    }

    /** IHDR says 60000 x 60000 - a decompression bomb with valid checksums. */
    public static function pngBomb(): string
    {
        $ihdr = pack('NN', 60000, 60000) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', (string) gzcompress(str_repeat("\0", 1024))) . self::chunk('IEND', '');
    }

    public static function pngWithBrokenCrc(): string
    {
        $png = self::png();
        $png[29] = chr(ord($png[29]) ^ 0xFF); // inside the IHDR checksum

        return $png;
    }

    public static function jpegWithoutEnd(): string
    {
        return substr(self::jpeg(), 0, -2);
    }

    public static function webpLyingAboutSize(): string
    {
        $webp = self::webp();

        return substr($webp, 0, 4) . pack('V', strlen($webp) * 4) . substr($webp, 8);
    }

    /* -------------------------------------------------------------- helpers */

    public static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** A big-endian TIFF block with orientation and an ImageDescription carrying `$text`. */
    public static function tiff(int $orientation, string $text): string
    {
        $text   .= "\0";
        $entries = 2;
        $dataAt  = 8 + 2 + 12 * $entries + 4;

        return 'MM' . pack('n', 42) . pack('N', 8)
            . pack('n', $entries)
            . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0)
            . pack('nnNN', 0x010E, 2, strlen($text), $dataAt)
            . pack('N', 0)
            . $text;
    }

    private static function u24(int $value): string
    {
        return chr($value & 0xFF) . chr(($value >> 8) & 0xFF) . chr(($value >> 16) & 0xFF);
    }

    /**
     * @param resource|\GdImage $image
     */
    private static function paint($image, int $width, int $height): void
    {
        for ($x = 0; $x < $width; $x += 8) {
            $color = imagecolorallocate($image, ($x * 4) % 256, 100, 255 - ($x * 4) % 256);
            imagefilledrectangle($image, $x, 0, $x + 7, $height - 1, (int) $color);
        }
    }

    private static function capture(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
