<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

use Dock\AltGenerator\Exception\InvalidImageException;

/**
 * Turns a file into the image that may leave the server, or refuses it:
 *
 *  1. ImageInspector proves from the content that it is a supported image and nothing else;
 *  2. it is re-encoded from its pixels (GD) or stripped to its pixel-carrying parts;
 *  3. the result is inspected again - what is uploaded has passed the same checks as the input.
 */
final class ImageSanitizer
{
    /** @var ImagePolicy */
    private $policy;

    /** @var ImageInspector */
    private $inspector;

    /** @var GdReencoder */
    private $reencoder;

    /** @var ImageStripper */
    private $stripper;

    public function __construct(ImagePolicy $policy, ?GdReencoder $reencoder = null)
    {
        $this->policy    = $policy;
        $this->inspector = new ImageInspector($policy);
        $this->reencoder = $reencoder ?? new GdReencoder();
        $this->stripper  = new ImageStripper();
    }

    public function prepare(ImageSource $source): PreparedImage
    {
        $bytes = $source->bytes();
        $info  = $this->inspector->inspect($bytes);

        // Nothing after the end marker is ever looked at again.
        $image = (string) substr($bytes, 0, $info->end);

        [$out, $method] = $this->sanitize($image, $info);

        $checked = $this->inspector->inspect($out);

        return new PreparedImage(
            $out,
            $checked->format,
            $checked->width,
            $checked->height,
            FileName::forUpload($source->name(), $checked->format),
            $method,
            $info->format,
            strlen($bytes)
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sanitize(string $image, ImageInfo $info): array
    {
        $mode = $this->policy->mode();

        if ($mode !== ImagePolicy::MODE_STRIP && $this->reencoder->canReencode($info, $this->policy)) {
            $result = $this->reencoder->reencode($image, $info, $this->policy);

            if ($result !== null && strlen($result['bytes']) <= $this->policy->maxBytes()) {
                return [$result['bytes'], PreparedImage::REENCODED];
            }

            if ($mode === ImagePolicy::MODE_REENCODE) {
                throw new InvalidImageException(InvalidImageException::MALFORMED, 'The image could not be decoded.');
            }
        } elseif ($mode === ImagePolicy::MODE_REENCODE) {
            throw new InvalidImageException(InvalidImageException::CANNOT_SANITIZE, 'This server cannot re-encode the image, and the policy requires it.');
        }

        if (!ImageStripper::supports($info->format)) {
            throw new InvalidImageException(InvalidImageException::CANNOT_SANITIZE, 'This format can only be sent re-encoded, and GD cannot re-encode it here. Convert it to JPEG, PNG or WebP.');
        }

        return [$this->stripper->strip($image, $info), PreparedImage::STRIPPED];
    }
}
