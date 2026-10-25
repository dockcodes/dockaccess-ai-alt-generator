<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Image;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\InvalidImageException;

/**
 * The bytes of an image and the name it is shown under. Reading is where most leaks start - a path
 * from a request that points at wp-config.php, a symlink out of the uploads folder, a `phar://` URL -
 * so a file is only read from inside directories the integration names, through the real path, and
 * only up to the size limit.
 *
 * Nothing here decides whether the bytes are an image; ImageInspector does, from the content.
 */
final class ImageSource
{
    /** @var string */
    private $bytes;

    /** @var string */
    private $name;

    private function __construct(string $bytes, string $name)
    {
        $this->bytes = $bytes;
        $this->name  = $name;
    }

    /**
     * Reads a file that must lie inside one of `$allowedDirectories` once symlinks are resolved -
     * e.g. [wp_upload_dir()['basedir']], [_PS_PROD_IMG_DIR_], [JPATH_ROOT . '/images'].
     *
     * @param string[] $allowedDirectories
     */
    public static function fromFile(string $path, array $allowedDirectories, int $maxBytes = ImagePolicy::API_MAX_BYTES): self
    {
        $roots = self::roots($allowedDirectories);

        if ($path === '' || strpos($path, "\0") !== false || self::isStreamUrl($path)) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'Only local files are read; stream wrappers and URLs are refused.');
        }

        $real = realpath($path);

        if ($real === false || !self::isInside($real, $roots)) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The file does not exist or lies outside the allowed directories.');
        }

        return new self(self::readFile($path, $real, $maxBytes), self::basename($path));
    }

    /**
     * An upload as PHP received it (an entry of $_FILES). Only a file PHP itself stored for this
     * request is accepted.
     *
     * @param array<string, mixed> $file
     */
    public static function fromUpload(array $file, int $maxBytes = ImagePolicy::API_MAX_BYTES): self
    {
        $error = $file['error'] ?? null;
        $tmp   = $file['tmp_name'] ?? null;

        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The upload did not complete or is not an uploaded file.');
        }

        $real = realpath($tmp);

        if ($real === false) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The uploaded file is gone.');
        }

        $name = is_string($file['name'] ?? null) ? self::basename($file['name']) : 'image';

        return new self(self::readFile($tmp, $real, $maxBytes), $name);
    }

    /**
     * Bytes the integration already holds (a framework's uploaded file, an image fetched by the CMS).
     */
    public static function fromBytes(string $bytes, string $name = 'image', int $maxBytes = ImagePolicy::API_MAX_BYTES): self
    {
        if ($bytes === '') {
            throw new InvalidImageException(InvalidImageException::EMPTY_FILE, 'The image is empty.');
        }

        if (strlen($bytes) > $maxBytes) {
            throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
        }

        return new self($bytes, self::basename($name));
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * The original base name, untrusted: FileName::forUpload() makes it safe to send.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'bytes' => strlen($this->bytes)];
    }

    /**
     * Integrations pass configuration here, so every entry is checked, not trusted to be a string.
     *
     * @param array<mixed> $directories
     *
     * @return string[]
     */
    private static function roots(array $directories): array
    {
        $roots = [];

        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory === '' || self::isStreamUrl($directory)) {
                continue;
            }

            $real = realpath($directory);

            if ($real !== false && is_dir($real)) {
                $roots[] = rtrim($real, '/\\');
            }
        }

        if ($roots === []) {
            throw new ConfigurationException('Name at least one existing directory images may be read from.');
        }

        return $roots;
    }

    /**
     * @param string[] $roots
     */
    private static function isInside(string $real, array $roots): bool
    {
        foreach ($roots as $root) {
            // realpath() uses DIRECTORY_SEPARATOR; a filesystem root ("/", "C:\") was trimmed to "" or "C:".
            $prefix = ($root === '' ? '' : $root) . DIRECTORY_SEPARATOR;

            if (strpos($real, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function isStreamUrl(string $path): bool
    {
        // "C:\..." and "C:/..." are Windows paths, not schemes.
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return false;
        }

        return preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $path) === 1;
    }

    /**
     * Opens the resolved path and proves the open file is the one that was checked: if anything along
     * the way was swapped for a symlink in between, the inode or the real path no longer match.
     */
    private static function readFile(string $path, string $real, int $maxBytes): string
    {
        $handle = @fopen($real, 'rb');

        if ($handle === false) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The file cannot be read.');
        }

        try {
            $opened = fstat($handle);
            $linked = @lstat($real);

            if (
                $opened === false
                || $linked === false
                || ($opened['mode'] & 0170000) !== 0100000
                || ($linked['mode'] & 0170000) !== 0100000
                || realpath($path) !== $real
                || ($opened['ino'] !== 0 && ($opened['ino'] !== $linked['ino'] || $opened['dev'] !== $linked['dev']))
            ) {
                throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The file is not a regular file or changed while it was opened.');
            }

            if ($opened['size'] === 0) {
                throw new InvalidImageException(InvalidImageException::EMPTY_FILE, 'The image is empty.');
            }

            if ($opened['size'] > $maxBytes) {
                throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
            }

            // One byte more than allowed tells a file that grew after fstat() apart.
            $bytes = stream_get_contents($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new InvalidImageException(InvalidImageException::EMPTY_FILE, 'The image is empty.');
        }

        if (strlen($bytes) > $maxBytes) {
            throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
        }

        return $bytes;
    }

    private static function basename(string $name): string
    {
        $parts = preg_split('#[/\\\\]#', $name);
        $base  = is_array($parts) ? (string) end($parts) : $name;

        return $base === '' ? 'image' : $base;
    }
}
