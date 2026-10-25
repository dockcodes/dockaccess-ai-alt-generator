<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Result;

use Dock\AltGenerator\Image\PreparedImage;

/**
 * What the generator made of one image. `imageUuid()` identifies the image in the account (keep it
 * to update the alt later), `setUuid()` the image set it joined.
 */
final class GenerationResult
{
    /** @var string */
    private $imageUuid;

    /** @var string */
    private $setUuid;

    /** @var string|null */
    private $setRef;

    /** @var Alt[] */
    private $alts;

    /** @var PreparedImage */
    private $image;

    /**
     * @param Alt[] $alts At least one
     */
    public function __construct(string $imageUuid, string $setUuid, ?string $setRef, array $alts, PreparedImage $image)
    {
        $this->imageUuid = $imageUuid;
        $this->setUuid   = $setUuid;
        $this->setRef    = $setRef;
        $this->alts      = array_values($alts);
        $this->image     = $image;
    }

    public function imageUuid(): string
    {
        return $this->imageUuid;
    }

    public function setUuid(): string
    {
        return $this->setUuid;
    }

    public function setRef(): ?string
    {
        return $this->setRef;
    }

    /**
     * @return Alt[]
     */
    public function alts(): array
    {
        return $this->alts;
    }

    /**
     * The first alt - the one to apply when only one is wanted.
     */
    public function alt(): Alt
    {
        return $this->alts[0];
    }

    /**
     * The image exactly as it was uploaded (sanitized), for logs and statistics.
     */
    public function image(): PreparedImage
    {
        return $this->image;
    }
}
