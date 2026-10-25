<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The request did not complete: DNS, TCP, TLS, a timeout or an oversized answer. The message is
 * redacted; `isRetryable()` is true only when the request certainly never reached the API, so a
 * retry cannot spend the daily limit twice.
 */
final class TransportException extends \RuntimeException implements AltGeneratorException
{
    /** @var bool */
    private $retryable;

    public function __construct(string $message, bool $retryable = false, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
