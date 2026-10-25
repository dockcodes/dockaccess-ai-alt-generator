<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

use Dock\AltGenerator\Exception\ConfigurationException;

/**
 * What an image must be before it is sent, and how it is cleaned. Immutable.
 *
 * Every image is sanitized - there is no mode that sends the file as it is:
 *  - MODE_AUTO (default): decode and re-encode with GD (pixels only, scaled down to `maxEdge`), and
 *    where GD cannot (missing, unsupported format, not enough memory) strip metadata and appended
 *    data at the byte level. AVIF and HEIC have no byte-level stripper and need GD.
 *  - MODE_REENCODE: only re-encoding is acceptable; an image GD cannot re-encode is refused.
 *  - MODE_STRIP: never decode, always strip at the byte level (for hosts where decoding untrusted
 *    images in PHP is not wanted).
 */
final class ImagePolicy
{
    public const MODE_AUTO     = 'auto';
    public const MODE_REENCODE = 'reencode';
    public const MODE_STRIP    = 'strip';

    /** The API refuses larger uploads. */
    public const API_MAX_BYTES = 10485760;

    /** @var int */
    private $maxBytes = self::API_MAX_BYTES;

    /** @var int */
    private $maxDimension = 20000;

    /** @var int */
    private $maxPixels = 50000000;

    /** @var int */
    private $maxFrames = 500;

    /** @var int */
    private $maxEdge = 2048;

    /** @var int */
    private $jpegQuality = 85;

    /** @var string */
    private $mode = self::MODE_AUTO;

    /** @var string[] */
    private $formats = ImageFormat::ALL;

    public static function secure(): self
    {
        return new self();
    }

    /**
     * Files above this are refused before they are read whole; at most the API's 10 MiB.
     */
    public function withMaxBytes(int $maxBytes): self
    {
        if ($maxBytes < 1024 || $maxBytes > self::API_MAX_BYTES) {
            throw new ConfigurationException('The size limit must be between 1 KiB and 10 MiB.');
        }

        $copy           = clone $this;
        $copy->maxBytes = $maxBytes;

        return $copy;
    }

    /**
     * Images larger than this (pixels per side or in total) are refused - decompression bombs declare
     * huge dimensions in a few bytes.
     */
    public function withPixelLimits(int $maxDimension, int $maxPixels): self
    {
        if ($maxDimension < 64 || $maxDimension > 65535 || $maxPixels < 4096 || $maxPixels > 268435456) {
            throw new ConfigurationException('Pixel limits must satisfy 64 <= side <= 65535 and 4096 <= total <= 268435456.');
        }

        $copy               = clone $this;
        $copy->maxDimension = $maxDimension;
        $copy->maxPixels    = $maxPixels;

        return $copy;
    }

    /**
     * The longest side a re-encoded image keeps. The generator does not need more to describe it, and
     * less leaves the server.
     */
    public function withMaxEdge(int $maxEdge): self
    {
        if ($maxEdge < 256 || $maxEdge > 8192) {
            throw new ConfigurationException('The longest side must be between 256 and 8192 pixels.');
        }

        $copy          = clone $this;
        $copy->maxEdge = $maxEdge;

        return $copy;
    }

    public function withJpegQuality(int $quality): self
    {
        if ($quality < 50 || $quality > 95) {
            throw new ConfigurationException('The JPEG quality must be between 50 and 95.');
        }

        $copy              = clone $this;
        $copy->jpegQuality = $quality;

        return $copy;
    }

    public function withMode(string $mode): self
    {
        if (!in_array($mode, [self::MODE_AUTO, self::MODE_REENCODE, self::MODE_STRIP], true)) {
            throw new ConfigurationException('Unknown sanitization mode.');
        }

        $copy       = clone $this;
        $copy->mode = $mode;

        return $copy;
    }

    /**
     * Narrows the accepted formats, e.g. to [ImageFormat::JPEG, ImageFormat::PNG].
     *
     * @param string[] $formats
     */
    public function withFormats(array $formats): self
    {
        $formats = array_values(array_unique($formats));

        if ($formats === [] || array_diff($formats, ImageFormat::ALL) !== []) {
            throw new ConfigurationException('Formats must be a non-empty subset of ImageFormat::ALL.');
        }

        $copy          = clone $this;
        $copy->formats = $formats;

        return $copy;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxDimension(): int
    {
        return $this->maxDimension;
    }

    public function maxPixels(): int
    {
        return $this->maxPixels;
    }

    public function maxFrames(): int
    {
        return $this->maxFrames;
    }

    public function maxEdge(): int
    {
        return $this->maxEdge;
    }

    public function jpegQuality(): int
    {
        return $this->jpegQuality;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function accepts(string $format): bool
    {
        return in_array($format, $this->formats, true);
    }
}
