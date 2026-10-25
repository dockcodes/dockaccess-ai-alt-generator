<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * Every exception this package throws. Messages are in English and never carry the account key,
 * request URLs with their query or file system paths; integrations branch on the type and on
 * the machine readable reason, and translate for their users.
 */
interface AltGeneratorException extends \Throwable
{
}
