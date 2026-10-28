<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Connection;

use Dock\AltGenerator\Secret;

/**
 * A site connected to a dockaccess.org account - what `integrations/connect` hands back.
 *
 * Store the three values the integration needs later, all of them as secrets (an environment file,
 * a secrets vault, encrypted settings - never a public config file):
 *
 * - `siteToken()` - the site in the account,
 * - `accountKey()` - the key the generator is called with (`Config::create()`),
 * - `secret()` - the connection secret `verify()` and alt pushes are authenticated with.
 */
final class Connection
{
    /** @var string */
    private $siteToken;

    /** @var Secret */
    private $accountKey;

    /** @var Secret */
    private $secret;

    /** @var int */
    private $integrationId;

    /** @var string */
    private $system;

    /** @var string */
    private $homeUrl;

    /** @var bool */
    private $altEnabled;

    /** @var bool */
    private $domainVerified;

    /** @var string */
    private $accountName;

    public function __construct(
        string $siteToken,
        Secret $accountKey,
        Secret $secret,
        int $integrationId,
        string $system,
        string $homeUrl,
        bool $altEnabled,
        bool $domainVerified,
        string $accountName
    ) {
        $this->siteToken      = $siteToken;
        $this->accountKey     = $accountKey;
        $this->secret         = $secret;
        $this->integrationId  = $integrationId;
        $this->system         = $system;
        $this->homeUrl        = $homeUrl;
        $this->altEnabled     = $altEnabled;
        $this->domainVerified = $domainVerified;
        $this->accountName    = $accountName;
    }

    public function siteToken(): string
    {
        return $this->siteToken;
    }

    public function accountKey(): Secret
    {
        return $this->accountKey;
    }

    public function secret(): Secret
    {
        return $this->secret;
    }

    public function integrationId(): int
    {
        return $this->integrationId;
    }

    public function system(): string
    {
        return $this->system;
    }

    public function homeUrl(): string
    {
        return $this->homeUrl;
    }

    /**
     * Whether the alt generator is switched on for the site (and not paused by its plan).
     */
    public function isAltEnabled(): bool
    {
        return $this->altEnabled;
    }

    /**
     * False for a local address (localhost, a private IP), which may connect without being one of
     * the site's domains - useful for development, not for production.
     */
    public function isDomainVerified(): bool
    {
        return $this->domainVerified;
    }

    /**
     * Who connected: the display name of the account user, already plain text.
     */
    public function accountName(): string
    {
        return $this->accountName;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'siteToken'      => $this->siteToken,
            'accountKey'     => Secret::REDACTED,
            'secret'         => Secret::REDACTED,
            'integrationId'  => $this->integrationId,
            'system'         => $this->system,
            'homeUrl'        => $this->homeUrl,
            'altEnabled'     => $this->altEnabled,
            'domainVerified' => $this->domainVerified,
        ];
    }
}
