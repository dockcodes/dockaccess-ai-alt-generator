<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Result;

/**
 * The account's generations in the current window against its limit. The free plan counts the UTC
 * day, paid plans the UTC calendar month (a whole month's images may go in one day).
 */
final class Usage
{
    public const DAY = 'day';

    public const MONTH = 'month';

    /** @var int */
    private $used;

    /** @var int */
    private $limit;

    /** @var string */
    private $period;

    /** @var \DateTimeImmutable|null */
    private $resetsAt;

    public function __construct(int $used, int $limit, string $period = self::DAY, ?\DateTimeImmutable $resetsAt = null)
    {
        $this->used     = max(0, $used);
        $this->limit    = max(0, $limit);
        $this->period   = $period === self::MONTH ? self::MONTH : self::DAY;
        $this->resetsAt = $resetsAt;
    }

    /**
     * `Usage::DAY` or `Usage::MONTH`. An API from before monthly plans counts the day.
     */
    public function period(): string
    {
        return $this->period;
    }

    public function isMonthly(): bool
    {
        return $this->period === self::MONTH;
    }

    /**
     * When the window starts over (UTC), null when the API did not say.
     */
    public function resetsAt(): ?\DateTimeImmutable
    {
        return $this->resetsAt;
    }

    public function used(): int
    {
        return $this->used;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }
}
