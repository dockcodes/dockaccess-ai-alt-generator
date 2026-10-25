<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

use Dock\AltGenerator\Exception\InvalidImageException;

/**
 * Rebuilds an image from the parts that carry pixels and how to show them, and nothing else - no EXIF
 * (GPS, camera serials), XMP, comments, text chunks, ICC profiles, application data or anything after
 * the end marker. Used when GD cannot re-encode the image, or when the policy says not to decode.
 *
 * Works on the part map ImageInspector built, so it copies only ranges that already passed the
 * structural checks.
 *
 * @internal
 */
final class ImageStripper
{
    /** JPEG segments kept: tables, frame header, restart interval, scans (with their data), JFIF and Adobe. */
    private const JPEG_KEEP = ['SOI', 'EOI', 'RST', 'SOF', 'SOS', 'DB', 'C4', 'DD', 'CC'];

    /** PNG chunks kept: the critical ones (their lengths checked by ImageInspector)... */
    private const PNG_KEEP = ['signature', 'IHDR', 'PLTE', 'IDAT', 'IEND'];

    /**
     * ...and those that change how the pixels look, only with the data length the PNG spec gives them:
     * a gAMA chunk of 10 KB with a valid checksum would carry anything past its 4 bytes.
     */
    private const PNG_ANCILLARY = ['tRNS' => [1, 256], 'gAMA' => [4, 4], 'cHRM' => [32, 32], 'sRGB' => [1, 1], 'sBIT' => [1, 4], 'pHYs' => [9, 9]];

    /** WebP chunks kept. EXIF, XMP and ICCP go, and their flags in VP8X are cleared. */
    private const WEBP_KEEP = ['VP8X', 'VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF'];

    public static function supports(string $format): bool
    {
        return in_array($format, [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP], true);
    }

    public function strip(string $bytes, ImageInfo $info): string
    {
        switch ($info->format) {
            case ImageFormat::JPEG:
                return $this->jpeg($bytes, $info);
            case ImageFormat::PNG:
                return $this->png($bytes, $info);
            case ImageFormat::GIF:
                return $this->keep($bytes, $info, ['header', 'image', 'control', 'trailer']);
            case ImageFormat::WEBP:
                return $this->webp($bytes, $info);
            default:
                throw new InvalidImageException(InvalidImageException::CANNOT_SANITIZE, 'This format can only be sent re-encoded, and GD cannot re-encode it here.');
        }
    }

    /**
     * @param string[] $kinds
     */
    private function keep(string $bytes, ImageInfo $info, array $kinds): string
    {
        $out = '';

        foreach ($info->parts as [$start, $length, $kind]) {
            if (in_array($kind, $kinds, true)) {
                $out .= substr($bytes, $start, $length);
            }
        }

        return $out;
    }

    private function png(string $bytes, ImageInfo $info): string
    {
        $out = '';

        foreach ($info->parts as [$start, $length, $kind]) {
            $bounds = self::PNG_ANCILLARY[$kind] ?? null;
            $keep   = in_array($kind, self::PNG_KEEP, true)
                || ($bounds !== null && $length - 12 >= $bounds[0] && $length - 12 <= $bounds[1]);

            if ($keep) {
                $out .= substr($bytes, $start, $length);
            }
        }

        return $out;
    }

    private function jpeg(string $bytes, ImageInfo $info): string
    {
        $out = '';

        foreach ($info->parts as [$start, $length, $kind]) {
            $segment = (string) substr($bytes, $start, $length);

            if ($kind === 'SOI') {
                $out .= $segment;

                // The orientation survives as a minimal EXIF block of one tag; nothing else of EXIF does.
                if ($info->orientation !== 1) {
                    $out .= self::orientationSegment($info->orientation);
                }

                continue;
            }

            if (in_array($kind, self::JPEG_KEEP, true)) {
                $out .= $segment;
            } elseif ($kind === 'E0' && substr($segment, 4, 5) === "JFIF\0" && strlen($segment) >= 18) {
                // Version, units and density only: the thumbnail JFIF allows may show the picture before it was cropped.
                $out .= "\xFF\xE0" . pack('n', 16) . substr($segment, 4, 12) . "\0\0";
            } elseif ($kind === 'EE' && substr($segment, 4, 5) === 'Adobe' && strlen($segment) >= 16) {
                // Version, flags and the colour transform, without whatever follows them.
                $out .= "\xFF\xEE" . pack('n', 14) . substr($segment, 4, 12);
            }
        }

        return $out;
    }

    private static function orientationSegment(int $orientation): string
    {
        $tiff = 'MM' . pack('n', 42) . pack('N', 8)   // header, IFD0 at 8
            . pack('n', 1)                            // one entry
            . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0)
            . pack('N', 0);                           // no next IFD
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    private function webp(string $bytes, ImageInfo $info): string
    {
        $body = 'WEBP';

        foreach ($info->parts as [$start, $length, $kind]) {
            if (!in_array($kind, self::WEBP_KEEP, true)) {
                continue;
            }

            $chunk = (string) substr($bytes, $start, $length);

            if ($kind === 'VP8X') {
                // Clear the ICC (0x20), EXIF (0x08) and XMP (0x04) flags: those chunks are gone.
                $chunk[8] = chr(ord($chunk[8]) & ~0x2C);
            }

            $body .= $chunk;
        }

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }
}
