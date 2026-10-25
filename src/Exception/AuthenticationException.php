<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The account key was not accepted (HTTP 401 / 403 "Invalid key"): wrong, revoked, or the site was
 * disconnected. Ask the user to connect again; retrying does not help.
 */
final class AuthenticationException extends ApiException
{
}
