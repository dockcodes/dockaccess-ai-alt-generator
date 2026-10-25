<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The client or a request was set up wrongly: an insecure base URL, an empty key, an unknown tone.
 * A programming error of the integration, not something to show to an end user.
 */
final class ConfigurationException extends \InvalidArgumentException implements AltGeneratorException
{
}
