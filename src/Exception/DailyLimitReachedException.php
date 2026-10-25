<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The account's daily quota is spent (HTTP 429, `X-Alt-Reason: limit_reached`). Only a new day or
 * a higher plan changes that - stop a bulk run here.
 */
final class DailyLimitReachedException extends ApiException
{
    /** @var int|null */
    private $dailyLimit;

    public function __construct(int $statusCode, string $apiMessage, ?int $dailyLimit)
    {
        parent::__construct($statusCode, $apiMessage);
        $this->dailyLimit = $dailyLimit;
    }

    public function getDailyLimit(): ?int
    {
        return $this->dailyLimit;
    }
}
