<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Connection;

use Dock\AltGenerator\Result\Usage;

/**
 * The answer of `integrations/verify`: may this site generate now?
 *
 * Verifying is cheap (no login, no password) and refreshes "last verified" in the account, so an
 * integration should verify before generating - or at least every few minutes - and stop when the
 * owner disconnected the site, switched the generator off or the plan paused it.
 */
final class Verification
{
    /** The generator is on; generate. */
    public const ACTIVE = 'active';

    /** The site's plan paused the generator; an upgrade in the account switches it back on. */
    public const PAUSED = 'alt_paused';

    /** The owner switched the generator off for the site in the account. */
    public const DISABLED = 'alt_disabled';

    /** @var string */
    private $state;

    /** @var int */
    private $integrationId;

    /** @var string */
    private $system;

    /** @var Usage|null */
    private $usage;

    /** @var int|null */
    private $websitesUsed;

    /** @var int|null */
    private $websitesLimit;

    public function __construct(string $state, int $integrationId, string $system, ?Usage $usage, ?int $websitesUsed, ?int $websitesLimit)
    {
        $this->state         = in_array($state, [self::ACTIVE, self::PAUSED, self::DISABLED], true) ? $state : self::DISABLED;
        $this->integrationId = $integrationId;
        $this->system        = $system;
        $this->usage         = $usage;
        $this->websitesUsed  = $websitesUsed;
        $this->websitesLimit = $websitesLimit;
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    /**
     * `Verification::ACTIVE`, `PAUSED` or `DISABLED`.
     */
    public function state(): string
    {
        return $this->state;
    }

    public function integrationId(): int
    {
        return $this->integrationId;
    }

    public function system(): string
    {
        return $this->system;
    }

    /**
     * The generator usage, when verify() was asked for it and the account could tell.
     */
    public function usage(): ?Usage
    {
        return $this->usage;
    }

    public function websitesUsed(): ?int
    {
        return $this->websitesUsed;
    }

    public function websitesLimit(): ?int
    {
        return $this->websitesLimit;
    }
}
