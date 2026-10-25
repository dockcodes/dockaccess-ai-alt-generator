<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

/**
 * An image ready to leave the server: verified, sanitized, named. These exact bytes are uploaded.
 */
final class PreparedImage
{
    /** Decoded and written again from its pixels. */
    public const REENCODED = 'reencoded';

    /** Rebuilt from its pixel-carrying parts, without decoding. */
    public const STRIPPED = 'stripped';

    /** @var string */
    private $bytes;

    /** @var string */
    private $format;

    /** @var int */
    private $width;

    /** @var int */
    private $height;

    /** @var string */
    private $fileName;

    /** @var string */
    private $method;

    /** @var string */
    private $sourceFormat;

    /** @var int */
    private $sourceBytes;

    public function __construct(string $bytes, string $format, int $width, int $height, string $fileName, string $method, string $sourceFormat, int $sourceBytes)
    {
        $this->bytes        = $bytes;
        $this->format       = $format;
        $this->width        = $width;
        $this->height       = $height;
        $this->fileName     = $fileName;
        $this->method       = $method;
        $this->sourceFormat = $sourceFormat;
        $this->sourceBytes  = $sourceBytes;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function mimeType(): string
    {
        return ImageFormat::mime($this->format);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    /**
     * REENCODED or STRIPPED.
     */
    public function method(): string
    {
        return $this->method;
    }

    public function sourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function sourceBytes(): int
    {
        return $this->sourceBytes;
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'format'       => $this->format,
            'width'        => $this->width,
            'height'       => $this->height,
            'fileName'     => $this->fileName,
            'method'       => $this->method,
            'bytes'        => strlen($this->bytes),
            'sourceFormat' => $this->sourceFormat,
            'sourceBytes'  => $this->sourceBytes,
        ];
    }
}
