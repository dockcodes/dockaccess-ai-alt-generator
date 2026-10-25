<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The file was refused before anything left the server. `getReason()` says why, in a form an
 * integration can translate; the message is meant for logs.
 */
final class InvalidImageException extends \RuntimeException implements AltGeneratorException
{
    /** The path is not a readable regular file inside the allowed directories. */
    public const NOT_ALLOWED = 'not_allowed';

    /** Nothing to read. */
    public const EMPTY_FILE = 'empty';

    /** Bigger than the policy allows (or than the API accepts). */
    public const TOO_LARGE = 'too_large';

    /** The content is not one of the supported raster formats, whatever the file is called. */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** It starts like an image but its structure does not hold together. */
    public const MALFORMED = 'malformed';

    /** Zero, absurd or too many pixels - a decompression bomb among others. */
    public const BAD_DIMENSIONS = 'bad_dimensions';

    /** PHP code or a PHAR stub inside the file: a polyglot, never sent. */
    public const EMBEDDED_CODE = 'embedded_code';

    /** The format is valid but this server cannot strip it safely, and the policy demands that. */
    public const CANNOT_SANITIZE = 'cannot_sanitize';

    /** @var string */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
