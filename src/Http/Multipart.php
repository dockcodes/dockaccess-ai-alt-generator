<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

use Dock\AltGenerator\Image\PreparedImage;

/**
 * A multipart/form-data body with one file field. The boundary is random and checked against the
 * content; the file name was already made safe by FileName, and is still stripped of quotes and line
 * breaks here, so no header can be injected.
 *
 * @internal
 */
final class Multipart
{
    /** @var string */
    private $boundary;

    /** @var string */
    private $body;

    public function __construct(string $field, PreparedImage $image)
    {
        do {
            $boundary = '----dkaltgen' . bin2hex(random_bytes(16));
        } while (strpos($image->bytes(), $boundary) !== false);

        $name = str_replace(['"', '\\', "\r", "\n", "\0"], '', $image->fileName());
        $eol  = "\r\n";

        $this->boundary = $boundary;
        $this->body     = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $name . '"' . $eol
            . 'Content-Type: ' . $image->mimeType() . $eol . $eol
            . $image->bytes() . $eol
            . '--' . $boundary . '--' . $eol;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function body(): string
    {
        return $this->body;
    }
}
