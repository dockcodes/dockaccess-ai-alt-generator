<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

use Dock\AltGenerator\Result\Usage;

/**
 * The account's quota is spent (HTTP 429, `X-Alt-Reason: limit_reached`): the day's on the free plan,
 * the month's on a paid one (`getPeriod()`). Only a new window or a higher plan changes that - stop a
 * bulk run here.
 */
final class DailyLimitReachedException extends ApiException
{
    /** @var int|null */
    private $dailyLimit;

    /** @var string|null */
    private $period;

    public function __construct(int $statusCode, string $apiMessage, ?int $dailyLimit, ?string $period = null)
    {
        parent::__construct($statusCode, $apiMessage);
        $this->dailyLimit = $dailyLimit;
        $this->period     = $period === Usage::MONTH || $period === Usage::DAY ? $period : null;
    }

    /**
     * The plan's limit for its window - a day or a month, see getPeriod(). The name predates monthly plans.
     */
    public function getDailyLimit(): ?int
    {
        return $this->dailyLimit;
    }

    /**
     * `Usage::DAY` (free plan) or `Usage::MONTH` (paid plans); null when the API did not say.
     */
    public function getPeriod(): ?string
    {
        return $this->period;
    }
}
