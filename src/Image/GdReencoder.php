<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

/**
 * Decodes an image with GD and writes only its pixels into a fresh file: oriented upright, scaled
 * down to the policy's longest side, JPEG for opaque images and WebP (or PNG) for transparent ones.
 * Whatever the original carried besides pixels cannot survive this.
 *
 * GD is only handed an image that already passed ImageInspector, and only when the decoded bitmap
 * fits into the memory PHP has left - running out of memory is a fatal error no caller could catch.
 * Bitmaps are freed with their last reference (PHP 7 resources too); imagedestroy() is deprecated in 8.5.
 *
 * @internal
 */
final class GdReencoder
{
    /** Bytes per pixel GD needs for a truecolor bitmap, with headroom. */
    private const BYTES_PER_PIXEL = 5;

    private const HEADROOM = 16777216;

    /** @var int|null Memory available, for tests; null reads it from PHP. */
    private $availableMemory;

    public function __construct(?int $availableMemory = null)
    {
        $this->availableMemory = $availableMemory;
    }

    /**
     * Whether this server can decode the format and has room for the bitmap.
     */
    public function canReencode(ImageInfo $info, ImagePolicy $policy): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return false;
        }

        // GD decodes the first frame of an animated WebP poorly or not at all.
        if ($info->format === ImageFormat::WEBP && $info->frames > 1) {
            return false;
        }

        $supported = [
            ImageFormat::JPEG => defined('IMG_JPG') ? IMG_JPG : 0,
            ImageFormat::PNG  => defined('IMG_PNG') ? IMG_PNG : 0,
            ImageFormat::GIF  => defined('IMG_GIF') ? IMG_GIF : 0,
            ImageFormat::WEBP => defined('IMG_WEBP') ? IMG_WEBP : 0,
            ImageFormat::AVIF => defined('IMG_AVIF') ? (int) constant('IMG_AVIF') : 0,
        ][$info->format] ?? 0;

        if ($supported === 0 || (imagetypes() & $supported) === 0) {
            return false;
        }

        [$width, $height] = self::target($info->width, $info->height, $policy->maxEdge());
        $needed           = ($info->width * $info->height + $width * $height) * self::BYTES_PER_PIXEL + self::HEADROOM;

        return $needed <= $this->availableMemory();
    }

    /**
     * @return array{bytes: string, format: string, width: int, height: int}|null Null when GD could not decode it
     */
    public function reencode(string $bytes, ImageInfo $info, ImagePolicy $policy): ?array
    {
        $image = self::quietly(static function () use ($bytes) {
            return imagecreatefromstring($bytes);
        });

        if ($image === null || $image === false) {
            return null;
        }

        return self::quietly(static function () use ($image, $info, $policy): ?array {
            $image = self::upright($image, $info->orientation);
            $image = self::scaled($image, $policy->maxEdge(), $info->hasAlpha);

            $format = $info->hasAlpha
                ? (function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0 ? ImageFormat::WEBP : ImageFormat::PNG)
                : ImageFormat::JPEG;
            $out = self::encode($image, $format, $policy->jpegQuality());

            if ($out === null && $format === ImageFormat::WEBP) {
                $format = ImageFormat::PNG;
                $out    = self::encode($image, $format, $policy->jpegQuality());
            }

            return $out === null ? null : ['bytes' => $out, 'format' => $format, 'width' => imagesx($image), 'height' => imagesy($image)];
        });
    }

    /**
     * Runs one GD step and returns its result, or null when it failed or raised any warning: a warning
     * means the data was damaged somewhere, and such a result is not trusted.
     *
     * @template T
     *
     * @param callable(): T $step
     *
     * @return T|null
     */
    private static function quietly(callable $step)
    {
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool {
            $warnings++;

            return true;
        });

        try {
            $result = $step();
        } catch (\Throwable $e) {
            return null;
        } finally {
            restore_error_handler();
        }

        return $warnings === 0 ? $result : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function target(int $width, int $height, int $maxEdge): array
    {
        $longest = max($width, $height);

        if ($longest <= $maxEdge) {
            return [$width, $height];
        }

        $ratio = $maxEdge / $longest;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    private function availableMemory(): int
    {
        if ($this->availableMemory !== null) {
            return $this->availableMemory;
        }

        $limit = self::bytes((string) ini_get('memory_limit'));

        if ($limit < 0) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - memory_get_usage(true));
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1073741824;
            case 'm':
                return $number * 1048576;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * @param \GdImage $image
     *
     * @return \GdImage
     */
    private static function upright($image, int $orientation)
    {
        if ($orientation === 1) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = [3 => 180, 4 => 0, 5 => 90, 6 => 270, 7 => 270, 8 => 90][$orientation] ?? 0;

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }


        return $rotated;
    }

    /**
     * @param \GdImage $image
     *
     * @return \GdImage
     */
    private static function scaled($image, int $maxEdge, bool $alpha)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        [$targetWidth, $targetHeight] = self::target($width, $height, $maxEdge);

        $canvas = imagecreatetruecolor(max(1, $targetWidth), max(1, $targetHeight));

        if ($canvas === false) {
            return $image;
        }

        if ($alpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent === false ? 0 : $transparent);
        } else {
            // A palette image with a transparent index still has to come out opaque.
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white === false ? 0 : $white);
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /**
     * @param \GdImage $image
     */
    private static function encode($image, string $format, int $quality): ?string
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            return null;
        }

        switch ($format) {
            case ImageFormat::JPEG:
                $ok = imagejpeg($image, $stream, $quality);
                break;
            case ImageFormat::WEBP:
                $ok = imagewebp($image, $stream, $quality);
                break;
            default:
                $ok = imagepng($image, $stream, 6);
        }

        rewind($stream);
        $out = stream_get_contents($stream);
        fclose($stream);

        return $ok && is_string($out) && $out !== '' ? $out : null;
    }

}
