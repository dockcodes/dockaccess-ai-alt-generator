<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The per minute cap of the generator (HTTP 429 without the daily limit reason). The same request
 * works again after `getRetryAfter()` seconds; nothing was generated or counted.
 */
final class RateLimitedException extends ApiException
{
    /** @var int */
    private $retryAfter;

    public function __construct(int $statusCode, string $apiMessage, int $retryAfter)
    {
        parent::__construct($statusCode, $apiMessage);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
