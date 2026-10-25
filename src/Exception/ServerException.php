<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The generator failed on its side (HTTP 5xx). Worth trying again later.
 */
final class ServerException extends ApiException
{
}
