<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The API answered, but not with what its contract promises - not JSON, missing fields, a status
 * this client does not know.
 */
final class UnexpectedResponseException extends \RuntimeException implements AltGeneratorException
{
    /** @var int */
    private $statusCode;

    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
