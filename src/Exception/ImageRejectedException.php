<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Exception;

/**
 * The API itself refused the image (HTTP 400 "Invalid image", 413 too large). Rare once the local
 * checks passed; retrying the same file does not help.
 */
final class ImageRejectedException extends ApiException
{
}
