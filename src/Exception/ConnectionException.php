<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * dockaccess.org refused to connect, verify or disconnect the site. `getReason()` says why, in a
 * form an integration can translate; `getApiMessage()` is the account's own (English) explanation.
 */
final class ConnectionException extends ApiException
{
    /** Wrong login or password; for verify(): unknown site token or connection secret. */
    public const INVALID_CREDENTIALS = 'invalid_credentials';

    /** The account owner has not confirmed the e-mail address yet. */
    public const EMAIL_NOT_VERIFIED = 'email_not_verified';

    /** The site token belongs to another account. */
    public const TOKEN_NOT_OWNED = 'token_not_owned';

    /** The site address is not one of the domains of the site in the account. */
    public const DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    /** The generator is switched off for the site in the account. */
    public const ALT_DISABLED = 'alt_disabled';

    /** The site's plan paused the generator. */
    public const ALT_PAUSED = 'alt_paused';

    /** Another connection exists for this address; repeat with ConnectRequest::withOverwrite(). */
    public const ALREADY_CONNECTED = 'already_connected';

    /** The owner disconnected the site in the account; connect again. */
    public const DISCONNECTED = 'disconnected';

    /** disconnect(): there is no connection for this address. */
    public const NOT_CONNECTED = 'not_connected';

    /** Too many failed attempts from this server; wait and try again. */
    public const TOO_MANY_ATTEMPTS = 'too_many_attempts';

    /** A field was missing or not valid. */
    public const INVALID_REQUEST = 'invalid_request';

    /** Anything else. */
    public const REFUSED = 'refused';

    /** @var string */
    private $reason;

    public function __construct(int $statusCode, string $apiMessage, string $reason)
    {
        parent::__construct($statusCode, $apiMessage);
        $this->message = sprintf('dockaccess.org refused the request (HTTP %d): %s', $statusCode, $apiMessage);
        $this->reason  = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * After verify() or disconnect(): the account no longer knows this connection, so the integration
     * should forget what it stored. (After connect(), INVALID_CREDENTIALS means a wrong password.)
     */
    public function isConnectionGone(): bool
    {
        return in_array($this->reason, [self::DISCONNECTED, self::INVALID_CREDENTIALS, self::NOT_CONNECTED], true);
    }
}
