<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

/**
 * What ImageInspector found in a file. `end` is where the image proper ends: anything after it is
 * appended data (a ZIP, a script, a second image) and is never sent.
 *
 * @internal
 */
final class ImageInfo
{
    /** @var string */
    public $format;

    /** @var int */
    public $width;

    /** @var int */
    public $height;

    /** @var bool */
    public $hasAlpha;

    /** @var int */
    public $frames;

    /** @var int */
    public $end;

    /**
     * EXIF orientation of a JPEG, 1 (as stored) to 8.
     *
     * @var int
     */
    public $orientation;

    /**
     * Byte ranges [start, length] of the parts a byte-level stripper keeps, in order, plus the kind
     * of each part - filled by the parser that walked the structure.
     *
     * @var list<array{0: int, 1: int, 2: string}>
     */
    public $parts;

    /**
     * @param list<array{0: int, 1: int, 2: string}> $parts
     */
    public function __construct(string $format, int $width, int $height, bool $hasAlpha, int $frames, int $end, int $orientation, array $parts)
    {
        $this->format      = $format;
        $this->width       = $width;
        $this->height      = $height;
        $this->hasAlpha    = $hasAlpha;
        $this->frames      = $frames;
        $this->end         = $end;
        $this->orientation = $orientation;
        $this->parts       = $parts;
    }
}
