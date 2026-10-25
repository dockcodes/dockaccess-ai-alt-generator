<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

use Dock\AltGenerator\Exception\InvalidImageException;

/**
 * Decides what a file is from its bytes alone - never from its name or a client's MIME type - and
 * walks its whole structure with bounds checks: chunk by chunk, segment by segment, box by box.
 *
 * A file passes only if it is one of the supported raster formats from the first byte to its end
 * marker, with sane dimensions. PHP code or a PHAR stub anywhere in it - the usual payload of an
 * "image" polyglot - is refused outright. Data appended after the end marker is not refused (cameras
 * do that) but is recorded, and nothing after the end marker is ever sent.
 *
 * Parsing only reads; it never decodes pixels, so a hostile file cannot exhaust memory here.
 */
final class ImageInspector
{
    /** Markers of executable payloads. Five bytes or more: shorter ones occur in compressed data by chance. */
    private const CODE_MARKERS = ['<?php', '__halt_compiler'];

    /** Upper bound for chunks, segments, blocks and boxes walked in one file. */
    private const MAX_PARTS = 100000;

    /** @var ImagePolicy */
    private $policy;

    public function __construct(ImagePolicy $policy)
    {
        $this->policy = $policy;
    }

    public function inspect(string $bytes): ImageInfo
    {
        $length = strlen($bytes);

        if ($length === 0) {
            throw new InvalidImageException(InvalidImageException::EMPTY_FILE, 'The image is empty.');
        }

        if ($length > $this->policy->maxBytes()) {
            throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
        }

        foreach (self::CODE_MARKERS as $marker) {
            if (stripos($bytes, $marker) !== false) {
                throw new InvalidImageException(InvalidImageException::EMBEDDED_CODE, 'The file carries PHP code or a PHAR stub.');
            }
        }

        $format = self::sniff($bytes);

        if ($format === null) {
            throw new InvalidImageException(InvalidImageException::UNSUPPORTED_FORMAT, 'The content is not a JPEG, PNG, GIF, WebP, AVIF or HEIC image.');
        }

        if (!$this->policy->accepts($format)) {
            throw new InvalidImageException(InvalidImageException::UNSUPPORTED_FORMAT, 'This image format is not accepted here.');
        }

        switch ($format) {
            case ImageFormat::PNG:
                $info = $this->png($bytes);
                break;
            case ImageFormat::JPEG:
                $info = $this->jpeg($bytes);
                break;
            case ImageFormat::GIF:
                $info = $this->gif($bytes);
                break;
            case ImageFormat::WEBP:
                $info = $this->webp($bytes);
                break;
            default:
                $info = $this->isobmff($bytes, $format);
        }

        $this->checkDimensions($info);

        return $info;
    }

    /**
     * The format from the leading bytes, or null for anything else (PHP, HTML, SVG, ZIP, PDF...).
     */
    public static function sniff(string $bytes): ?string
    {
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return ImageFormat::PNG;
        }

        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return ImageFormat::JPEG;
        }

        if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0) {
            return ImageFormat::GIF;
        }

        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ImageFormat::WEBP;
        }

        if (strlen($bytes) >= 16 && substr($bytes, 4, 4) === 'ftyp') {
            $size   = self::u32be($bytes, 0);
            $brands = [];

            for ($at = 8; $at + 4 <= min($size, strlen($bytes), 256); $at += 4) {
                if ($at !== 12) { // the minor version, not a brand
                    $brands[] = substr($bytes, $at, 4);
                }
            }

            if (array_intersect($brands, ['avif', 'avis']) !== []) {
                return ImageFormat::AVIF;
            }

            if (array_intersect($brands, ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1']) !== []) {
                return ImageFormat::HEIC;
            }
        }

        return null;
    }

    private function checkDimensions(ImageInfo $info): void
    {
        $max = $this->policy->maxDimension();

        if ($info->width < 1 || $info->height < 1 || $info->width > $max || $info->height > $max) {
            throw new InvalidImageException(InvalidImageException::BAD_DIMENSIONS, 'The image dimensions are zero or too large.');
        }

        if ($info->width * $info->height > $this->policy->maxPixels()) {
            throw new InvalidImageException(InvalidImageException::BAD_DIMENSIONS, 'The image has more pixels than allowed.');
        }

        if ($info->frames < 1 || $info->frames > $this->policy->maxFrames()) {
            throw new InvalidImageException(InvalidImageException::BAD_DIMENSIONS, 'The image has more frames than allowed.');
        }
    }

    /* ------------------------------------------------------------------ PNG */

    private function png(string $b): ImageInfo
    {
        $n        = strlen($b);
        $at       = 8;
        $parts    = [[0, 8, 'signature']];
        $width    = $height = 0;
        $hasAlpha = false;
        $frames   = 1;
        $sawIdat  = false;
        $count    = 0;

        while (true) {
            if ($at + 12 > $n || ++$count > self::MAX_PARTS) {
                throw self::malformed('The PNG ends before its IEND chunk.');
            }

            $len  = self::u32be($b, $at);
            $type = substr($b, $at + 4, 4);

            if ($len > 0x7FFFFFFF || $at + 12 + $len > $n || preg_match('/^[A-Za-z]{4}$/D', $type) !== 1) {
                throw self::malformed('A PNG chunk is broken.');
            }

            $data = (string) substr($b, $at + 8, $len);

            if ((crc32($type . $data) & 0xFFFFFFFF) !== self::u32be($b, $at + 8 + $len)) {
                throw self::malformed('A PNG chunk fails its checksum.');
            }

            if ($count === 1 && ($type !== 'IHDR' || $len !== 13)) {
                throw self::malformed('A PNG must start with its header chunk.');
            }

            if ($type === 'IHDR') {
                if ($count !== 1) {
                    throw self::malformed('A PNG has a second header chunk.');
                }

                $width     = self::u32be($data, 0);
                $height    = self::u32be($data, 4);
                $depth     = ord($data[8]);
                $colorType = ord($data[9]);

                if (!in_array($colorType, [0, 2, 3, 4, 6], true) || !in_array($depth, [1, 2, 4, 8, 16], true) || ord($data[10]) !== 0 || ord($data[11]) !== 0 || ord($data[12]) > 1) {
                    throw self::malformed('The PNG header is not valid.');
                }

                $hasAlpha = $colorType === 4 || $colorType === 6;
            } elseif ($type === 'IDAT') {
                $sawIdat = true;
            } elseif (($type === 'PLTE' && ($len === 0 || $len > 768 || $len % 3 !== 0)) || ($type === 'IEND' && $len !== 0)) {
                throw self::malformed('A PNG chunk has a length its type does not allow.');
            } elseif ($type === 'tRNS') {
                $hasAlpha = true;
            } elseif ($type === 'acTL' && $len === 8) {
                $frames = max(1, self::u32be($data, 0));
            }

            $parts[] = [$at, 12 + $len, $type];
            $at += 12 + $len;

            if ($type === 'IEND') {
                break;
            }
        }

        if (!$sawIdat) {
            throw self::malformed('The PNG has no image data.');
        }

        return new ImageInfo(ImageFormat::PNG, $width, $height, $hasAlpha, $frames, $at, 1, $parts);
    }

    /* ----------------------------------------------------------------- JPEG */

    private function jpeg(string $b): ImageInfo
    {
        $n           = strlen($b);
        $at          = 2;
        $parts       = [[0, 2, 'SOI']];
        $width       = $height = 0;
        $orientation = 1;
        $sawSof      = false;
        $sawScan     = false;
        $count       = 0;

        while (true) {
            if ($at + 2 > $n || ++$count > self::MAX_PARTS) {
                throw self::malformed('The JPEG ends before its end marker.');
            }

            if ($b[$at] !== "\xFF") {
                throw self::malformed('A JPEG segment does not start with a marker.');
            }

            // Fill bytes: any number of 0xFF before the marker code.
            $start = $at;

            while ($at < $n && $b[$at] === "\xFF") {
                $at++;
            }

            if ($at >= $n) {
                throw self::malformed('The JPEG ends inside a marker.');
            }

            $marker = ord($b[$at]);
            $at++;

            if ($marker === 0xD9) { // EOI
                if (!$sawScan) {
                    throw self::malformed('The JPEG has no image data.');
                }

                $parts[] = [$start, $at - $start, 'EOI'];
                break;
            }

            if ($marker === 0xD8 || $marker === 0x00) {
                throw self::malformed('The JPEG has an unexpected marker.');
            }

            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $parts[] = [$start, $at - $start, 'RST'];
                continue;
            }

            if ($at + 2 > $n) {
                throw self::malformed('The JPEG ends inside a segment.');
            }

            $len = self::u16be($b, $at);

            if ($len < 2 || $at + $len > $n) {
                throw self::malformed('A JPEG segment is broken.');
            }

            $payload = (string) substr($b, $at + 2, $len - 2);
            $kind    = sprintf('%02X', $marker);

            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                if (strlen($payload) < 6) {
                    throw self::malformed('The JPEG frame header is broken.');
                }

                $height = self::u16be($payload, 1);
                $width  = self::u16be($payload, 3);
                $sawSof = true;
                $kind   = 'SOF';
            } elseif ($marker === 0xE1 && strncmp($payload, "Exif\0\0", 6) === 0) {
                $orientation = self::exifOrientation((string) substr($payload, 6));
            }

            $segmentEnd = $at + $len;

            if ($marker === 0xDA) { // SOS: the entropy-coded data runs to the next real marker
                if (!$sawSof) {
                    throw self::malformed('The JPEG scan comes before its frame header.');
                }

                $scanEnd = self::scanEnd($b, $segmentEnd);

                if ($scanEnd === null) {
                    throw self::malformed('The JPEG scan does not end.');
                }

                $parts[] = [$start, $scanEnd - $start, 'SOS'];
                $sawScan = true;
                $at      = $scanEnd;
                continue;
            }

            $parts[] = [$start, $segmentEnd - $start, $kind];
            $at      = $segmentEnd;
        }

        return new ImageInfo(ImageFormat::JPEG, $width, $height, false, 1, $at, $orientation, $parts);
    }

    /**
     * Where entropy-coded data ends: the first 0xFF that is not a stuffed byte (FF 00), a restart
     * marker (FF D0-D7) or fill (FF FF).
     */
    private static function scanEnd(string $b, int $from): ?int
    {
        $n  = strlen($b);
        $at = $from;

        while (($at = strpos($b, "\xFF", $at)) !== false) {
            if ($at + 1 >= $n) {
                return null;
            }

            $next = ord($b[$at + 1]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7) || $next === 0xFF) {
                $at += $next === 0xFF ? 1 : 2;
                continue;
            }

            return $at;
        }

        return null;
    }

    /**
     * The orientation tag (0x0112) of IFD0, or 1 when there is none or the TIFF block is broken -
     * a bad orientation never refuses an image, it only stops it from being rotated.
     */
    private static function exifOrientation(string $tiff): int
    {
        $n = strlen($tiff);

        if ($n < 8) {
            return 1;
        }

        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $le    = $order === 'II';
        $u16   = static function (int $at) use ($tiff, $le): int {
            $v = unpack($le ? 'v' : 'n', (string) substr($tiff, $at, 2));

            return is_array($v) && is_int($v[1] ?? null) ? $v[1] : 0;
        };
        $u32   = static function (int $at) use ($tiff, $le): int {
            $v = unpack($le ? 'V' : 'N', (string) substr($tiff, $at, 4));

            return is_array($v) && is_int($v[1] ?? null) ? $v[1] : 0;
        };

        if ($u16(2) !== 42) {
            return 1;
        }

        $ifd = $u32(4);

        if ($ifd < 8 || $ifd + 2 > $n) {
            return 1;
        }

        $entries = min($u16($ifd), 512);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;

            if ($entry + 12 > $n) {
                return 1;
            }

            if ($u16($entry) === 0x0112 && $u16($entry + 2) === 3) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /* ------------------------------------------------------------------ GIF */

    private function gif(string $b): ImageInfo
    {
        $n = strlen($b);

        if ($n < 13) {
            throw self::malformed('The GIF is truncated.');
        }

        $width    = self::u16le($b, 6);
        $height   = self::u16le($b, 8);
        $packed   = ord($b[10]);
        $at       = 13 + (($packed & 0x80) !== 0 ? 3 * (2 << ($packed & 0x07)) : 0);
        $parts    = [[0, $at, 'header']];
        $hasAlpha = false;
        $frames   = 0;
        $count    = 0;

        if ($at > $n) {
            throw self::malformed('The GIF colour table is truncated.');
        }

        while (true) {
            if ($at >= $n || ++$count > self::MAX_PARTS) {
                throw self::malformed('The GIF ends before its trailer.');
            }

            $start = $at;
            $block = ord($b[$at]);

            if ($block === 0x3B) {
                $parts[] = [$at, 1, 'trailer'];
                $at++;
                break;
            }

            if ($block === 0x2C) { // image descriptor
                if ($at + 10 > $n) {
                    throw self::malformed('A GIF image descriptor is truncated.');
                }

                $local = ord($b[$at + 9]);
                $at   += 10 + (($local & 0x80) !== 0 ? 3 * (2 << ($local & 0x07)) : 0);

                if ($at + 1 > $n) {
                    throw self::malformed('A GIF frame is truncated.');
                }

                $at = self::subBlocks($b, $at + 1); // after the LZW minimum code size
                $frames++;
                $parts[] = [$start, $at - $start, 'image'];
                continue;
            }

            if ($block === 0x21) { // extension
                if ($at + 2 > $n) {
                    throw self::malformed('A GIF extension is truncated.');
                }

                $label = ord($b[$at + 1]);

                if ($label === 0xF9 && $at + 4 <= $n && (ord($b[$at + 3]) & 0x01) !== 0) {
                    $hasAlpha = true;
                }

                $at = self::subBlocks($b, $at + 2);
                // A graphic control block is 21 F9 04 + 4 bytes + 00; a longer one carries something else.
                $control = $label === 0xF9 && $at - $start === 8 && ord($b[$start + 2]) === 4;
                $parts[] = [$start, $at - $start, $control ? 'control' : 'extension'];
                continue;
            }

            throw self::malformed('The GIF has an unknown block.');
        }

        return new ImageInfo(ImageFormat::GIF, $width, $height, $hasAlpha, $frames, $at, 1, $parts);
    }

    /**
     * Skips a chain of GIF data sub-blocks and returns the offset after its terminator.
     */
    private static function subBlocks(string $b, int $at): int
    {
        $n     = strlen($b);
        $count = 0;

        while (true) {
            if ($at >= $n || ++$count > 1000000) {
                throw self::malformed('A GIF data block is truncated.');
            }

            $size = ord($b[$at]);
            $at  += 1 + $size;

            if ($size === 0) {
                return $at;
            }

            if ($at > $n) {
                throw self::malformed('A GIF data block is truncated.');
            }
        }
    }

    /* ----------------------------------------------------------------- WebP */

    private function webp(string $b): ImageInfo
    {
        $n    = strlen($b);
        $size = self::u32le($b, 4);
        $end  = 8 + $size;

        if ($size < 4 || $end > $n) {
            throw self::malformed('The WebP size field does not match the file.');
        }

        $at       = 12;
        $parts    = [];
        $width    = $height = 0;
        $hasAlpha = false;
        $frames   = 1;
        $sawImage = false;
        $count    = 0;

        while ($at < $end) {
            if ($at + 8 > $end || ++$count > self::MAX_PARTS) {
                throw self::malformed('A WebP chunk is truncated.');
            }

            $fourcc = substr($b, $at, 4);
            $len    = self::u32le($b, $at + 4);
            $next   = $at + 8 + $len + ($len & 1);

            if ($len > 0x7FFFFFFF || $at + 8 + $len > $end || preg_match('/^[A-Z0-9 ]{4}$/D', $fourcc) !== 1) {
                throw self::malformed('A WebP chunk is broken.');
            }

            $data = (string) substr($b, $at + 8, $len);

            if ($fourcc === 'VP8X') {
                if ($len < 10 || $count !== 1) {
                    throw self::malformed('The WebP extended header is broken.');
                }

                $flags    = ord($data[0]);
                $hasAlpha = ($flags & 0x10) !== 0;
                $width    = self::u24le($data, 4) + 1;
                $height   = self::u24le($data, 7) + 1;
            } elseif ($fourcc === 'VP8 ') {
                if ($len < 10 || substr($data, 3, 3) !== "\x9D\x01\x2A") {
                    throw self::malformed('The WebP lossy bitstream is broken.');
                }

                if ($width === 0) {
                    $width  = self::u16le($data, 6) & 0x3FFF;
                    $height = self::u16le($data, 8) & 0x3FFF;
                }

                $sawImage = true;
            } elseif ($fourcc === 'VP8L') {
                if ($len < 5 || $data[0] !== "\x2F") {
                    throw self::malformed('The WebP lossless bitstream is broken.');
                }

                if ($width === 0) {
                    $bits     = self::u32le($data, 1);
                    $width    = ($bits & 0x3FFF) + 1;
                    $height   = (($bits >> 14) & 0x3FFF) + 1;
                    $hasAlpha = (($bits >> 28) & 1) === 1;
                }

                $sawImage = true;
            } elseif ($fourcc === 'ALPH') {
                $hasAlpha = true;
            } elseif ($fourcc === 'ANMF') {
                $frames   = $sawImage ? $frames + 1 : 1;
                $sawImage = true;
            }

            $parts[] = [$at, min($next, $end) - $at, $fourcc];
            $at      = $next;
        }

        if (!$sawImage) {
            throw self::malformed('The WebP has no image data.');
        }

        return new ImageInfo(ImageFormat::WEBP, $width, $height, $hasAlpha, $frames, $end, 1, $parts);
    }

    /* ------------------------------------------------------------ AVIF/HEIC */

    private function isobmff(string $b, string $format): ImageInfo
    {
        $n        = strlen($b);
        $at       = 0;
        $parts    = [];
        $width    = $height = 0;
        $sawMeta  = false;
        $count    = 0;

        while ($at < $n) {
            if (++$count > self::MAX_PARTS) {
                throw self::malformed('The image has too many boxes.');
            }

            [$type, $headerSize, $boxEnd] = self::box($b, $at, $n);

            if ($count === 1 && $type !== 'ftyp') {
                throw self::malformed('The image does not start with its file type box.');
            }

            if ($type === 'meta') {
                $sawMeta = true;
                // A FullBox: version and flags precede the children.
                foreach (self::ispe($b, $at + $headerSize + 4, $boxEnd, 0) as [$w, $h]) {
                    if ($w * $h > $width * $height) {
                        $width  = $w;
                        $height = $h;
                    }
                }
            }

            $parts[] = [$at, $boxEnd - $at, $type];
            $at      = $boxEnd;
        }

        if (!$sawMeta || $width === 0) {
            throw self::malformed('The image has no item properties.');
        }

        $alpha = strpos($b, 'auxiliary:alpha') !== false;

        return new ImageInfo($format, $width, $height, $alpha, 1, $at, 1, $parts);
    }

    /**
     * @return array{0: string, 1: int, 2: int} type, header size, end offset
     */
    private static function box(string $b, int $at, int $limit): array
    {
        if ($at + 8 > $limit) {
            throw self::malformed('A box header is truncated.');
        }

        $size   = self::u32be($b, $at);
        $type   = substr($b, $at + 4, 4);
        $header = 8;

        if ($size === 1) {
            if ($at + 16 > $limit) {
                throw self::malformed('A large box header is truncated.');
            }

            $high = self::u32be($b, $at + 8);
            $low  = self::u32be($b, $at + 12);

            if ($high !== 0) {
                throw self::malformed('A box is larger than the file.');
            }

            $size   = $low;
            $header = 16;
        } elseif ($size === 0) {
            $size = $limit - $at;
        }

        if ($size < $header || $at + $size > $limit || preg_match('/^[\x20-\x7E]{4}$/D', $type) !== 1) {
            throw self::malformed('A box is broken.');
        }

        return [$type, $header, $at + $size];
    }

    /**
     * Image spatial extents ('ispe') under meta/iprp/ipco.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function ispe(string $b, int $at, int $end, int $depth): array
    {
        if ($depth > 4) {
            return [];
        }

        $found = [];
        $count = 0;

        while ($at < $end && ++$count < 4096) {
            [$type, $header, $boxEnd] = self::box($b, $at, $end);

            if ($type === 'iprp' || $type === 'ipco') {
                $found = array_merge($found, self::ispe($b, $at + $header, $boxEnd, $depth + 1));
            } elseif ($type === 'ispe' && $boxEnd - $at >= $header + 12) {
                $found[] = [self::u32be($b, $at + $header + 4), self::u32be($b, $at + $header + 8)];
            }

            $at = $boxEnd;
        }

        return $found;
    }

    /* -------------------------------------------------------------- helpers */

    private static function malformed(string $message): InvalidImageException
    {
        return new InvalidImageException(InvalidImageException::MALFORMED, $message);
    }

    private static function u16be(string $b, int $at): int
    {
        return (ord($b[$at] ?? "\0") << 8) | ord($b[$at + 1] ?? "\0");
    }

    private static function u16le(string $b, int $at): int
    {
        return ord($b[$at] ?? "\0") | (ord($b[$at + 1] ?? "\0") << 8);
    }

    private static function u24le(string $b, int $at): int
    {
        return ord($b[$at] ?? "\0") | (ord($b[$at + 1] ?? "\0") << 8) | (ord($b[$at + 2] ?? "\0") << 16);
    }

    private static function u32be(string $b, int $at): int
    {
        return (ord($b[$at] ?? "\0") << 24) | (ord($b[$at + 1] ?? "\0") << 16) | (ord($b[$at + 2] ?? "\0") << 8) | ord($b[$at + 3] ?? "\0");
    }

    private static function u32le(string $b, int $at): int
    {
        return ord($b[$at] ?? "\0") | (ord($b[$at + 1] ?? "\0") << 8) | (ord($b[$at + 2] ?? "\0") << 16) | (ord($b[$at + 3] ?? "\0") << 24);
    }
}
