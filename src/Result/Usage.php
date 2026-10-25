<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Result;

/**
 * The account's generations today (UTC day) against its daily limit.
 */
final class Usage
{
    /** @var int */
    private $used;

    /** @var int */
    private $limit;

    public function __construct(int $used, int $limit)
    {
        $this->used  = max(0, $used);
        $this->limit = max(0, $limit);
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
