<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

/**
 * The name an image is uploaded under: the original base name made harmless - ASCII letters, digits,
 * dashes and underscores, no path, no second extension, and the extension of the format actually
 * sent (`shell.php.png` becomes `shell-php.png`, a PNG named `photo.jpg` becomes `photo.png`).
 *
 * The API shows this name in the account, and uses it with the bytes to recognise a re-upload.
 *
 * @internal
 */
final class FileName
{
    private const MAX_STEM = 100;

    public static function forUpload(string $name, string $format): string
    {
        $parts = preg_split('#[/\\\\]#', $name);
        $base  = is_array($parts) ? (string) end($parts) : $name;
        $dot   = strrpos($base, '.');
        $stem  = $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);

        $stem = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem);
        $stem = trim((string) preg_replace('/-{2,}/', '-', $stem), '-_');
        $stem = substr($stem, 0, self::MAX_STEM);

        return ($stem === '' ? 'image' : $stem) . '.' . ImageFormat::extension($format);
    }
}
