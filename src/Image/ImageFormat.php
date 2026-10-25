<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

/**
 * The raster formats the client recognises. SVG is deliberately absent: it is a document that can
 * carry scripts and external references, not pixels.
 */
final class ImageFormat
{
    public const JPEG = 'jpeg';
    public const PNG  = 'png';
    public const GIF  = 'gif';
    public const WEBP = 'webp';
    public const AVIF = 'avif';
    public const HEIC = 'heic';

    public const ALL = [self::JPEG, self::PNG, self::GIF, self::WEBP, self::AVIF, self::HEIC];

    private const MIME = [
        self::JPEG => 'image/jpeg',
        self::PNG  => 'image/png',
        self::GIF  => 'image/gif',
        self::WEBP => 'image/webp',
        self::AVIF => 'image/avif',
        self::HEIC => 'image/heic',
    ];

    private const EXTENSION = [
        self::JPEG => 'jpg',
        self::PNG  => 'png',
        self::GIF  => 'gif',
        self::WEBP => 'webp',
        self::AVIF => 'avif',
        self::HEIC => 'heic',
    ];

    private function __construct()
    {
    }

    public static function mime(string $format): string
    {
        return self::MIME[$format] ?? 'application/octet-stream';
    }

    public static function extension(string $format): string
    {
        return self::EXTENSION[$format] ?? 'bin';
    }
}
