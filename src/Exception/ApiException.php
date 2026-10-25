<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The API refused the request. `getApiMessage()` is the API's own explanation, cleaned of markup,
 * control characters and the account key, and cut to a safe length.
 */
class ApiException extends \RuntimeException implements AltGeneratorException
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $apiMessage;

    public function __construct(int $statusCode, string $apiMessage)
    {
        parent::__construct(sprintf('The alt generator refused the request (HTTP %d): %s', $statusCode, $apiMessage), $statusCode);
        $this->statusCode = $statusCode;
        $this->apiMessage = $apiMessage;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getApiMessage(): string
    {
        return $this->apiMessage;
    }
}
