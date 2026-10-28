<?php

declare(strict_types=1);

/*
 * Copies this library into a plugin that ships its own code (WordPress, PrestaShop, Joomla and
 * Magento modules installed from a zip) under a namespace of that plugin, so two plugins bundling
 * different versions cannot load each other's classes:
 *
 *   php bin/bundle.php <target directory> <Namespace\Prefix> [--guard=CONSTANT] [--index]
 *
 *   php bin/bundle.php ../my-plugin/vendor/dockcodes/dockaccess-alt-generator 'MyPlugin\Vendor' --guard=ABSPATH
 *
 * The target receives src/ with every Dock\AltGenerator reference prefixed, an autoload.php, the
 * LICENSE, and bundle.json with the version, the prefix and the SHA-256 of every file. Every PHP file
 * opens with a header naming the package, its version and its license, which extension directories
 * check for (the Joomla Extensions Directory requires a license notice in each file). --guard puts
 * `defined('CONSTANT') || exit;` into every file, for platforms that require it (ABSPATH, _JEXEC,
 * _PS_VERSION_). --index adds an index.php that only redirects into every directory, as PrestaShop
 * modules must have.
 *
 * A target that exists is only replaced when it holds a bundle.json of an earlier run.
 */

const NAMESPACE_ROOT = 'Dock\\AltGenerator';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arguments = array_slice($argv, 1);
$guard     = null;
$indexes   = false;

foreach ($arguments as $index => $argument) {
    if (strncmp($argument, '--guard=', 8) === 0) {
        $guard = substr($argument, 8);
        unset($arguments[$index]);
    } elseif ($argument === '--index') {
        $indexes = true;
        unset($arguments[$index]);
    }
}

$arguments = array_values($arguments);

if (count($arguments) !== 2) {
    fail('Usage: php bin/bundle.php <target directory> <Namespace\\Prefix> [--guard=CONSTANT] [--index]');
}

[$target, $prefix] = $arguments;
$prefix            = trim($prefix, '\\');

if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $prefix) !== 1) {
    fail('The prefix must be a namespace, e.g. MyPlugin\\Vendor.');
}

if ($guard !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $guard) !== 1) {
    fail('The guard must be a constant name, e.g. ABSPATH.');
}

$root    = dirname(__DIR__);
$source  = $root . '/src';
$version = versionOf($source . '/AltGenerator.php');

checkTarget($target);

$files    = [];
$guardPhp = $guard === null ? '' : "\n\\defined('" . $guard . "') || exit;\n";

foreach (sourceFiles($source) as $relative) {
    $code = (string) file_get_contents($source . '/' . $relative);
    $code = withHeader(prefixed($code, $prefix), $version);

    if ($guard !== null) {
        // Right after the namespace: only declare() may come before it.
        $code = preg_replace_callback('/^namespace [^;\n]+;\n/m', static function (array $match) use ($guardPhp): string {
            return $match[0] . $guardPhp;
        }, $code, 1, $count);

        if ($count !== 1) {
            fail('No namespace statement to put the guard after in ' . $relative . '.');
        }
    }

    $files['src/' . $relative] = (string) $code;
}

$files['autoload.php'] = withHeader(autoloader($prefix, $guardPhp), $version);
$files['LICENSE']      = (string) file_get_contents($root . '/LICENSE');

if ($indexes) {
    foreach (directoriesOf(array_keys($files)) as $directory) {
        $files[ltrim($directory . '/index.php', '/')] = directoryIndex();
    }

    ksort($files);
}
$files['bundle.json']  = json_encode([
    'package' => 'dockcodes/dockaccess-alt-generator',
    'version' => $version,
    'prefix'  => $prefix . '\\' . NAMESPACE_ROOT,
    'guard'   => $guard,
    'files'   => array_map(static function (string $content): string {
        return hash('sha256', $content);
    }, $files),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Built next to the target and swapped in only once it verified, so a failed run leaves the old bundle.
$staging = rtrim($target, '/\\') . '.bundling-' . bin2hex(random_bytes(4));

register_shutdown_function(static function () use ($staging): void {
    if (is_dir($staging)) {
        removeTree($staging);
    }
});

foreach ($files as $relative => $content) {
    $path = $staging . '/' . $relative;

    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
        fail('Cannot create ' . dirname($path) . '.');
    }

    if (file_put_contents($path, $content) === false) {
        fail('Cannot write ' . $path . '.');
    }
}

verify($staging, array_keys($files), $prefix);

if (is_dir($target)) {
    removeTree($target);
}

if (!rename($staging, $target)) {
    fail('Cannot move the bundle into ' . $target . '.');
}

fwrite(STDOUT, sprintf("Bundled dockaccess-alt-generator %s into %s as %s\\%s (%d files).\n", $version, $target, $prefix, NAMESPACE_ROOT, count($files)));

/* ------------------------------------------------------------------------------------------- */

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function versionOf(string $file): string
{
    if (preg_match("/public const VERSION = '([^']+)';/", (string) file_get_contents($file), $match) !== 1) {
        fail('No version found in ' . $file . '.');
    }

    return $match[1];
}

/**
 * @return list<string> Paths relative to src/, sorted
 */
function sourceFiles(string $source): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($source) + 1);
        }
    }

    sort($files);

    return $files;
}

/**
 * Every `Dock\AltGenerator` that opens a name - in namespace and use statements, fully qualified
 * names and docblocks - gets the prefix; nothing else in the code mentions the namespace.
 */
function prefixed(string $code, string $prefix): string
{
    return (string) preg_replace('/(?<![A-Za-z0-9_\\\\])(\\\\?)Dock\\\\AltGenerator(?=[\\\\;\s])/', '$1' . str_replace('\\', '\\\\', $prefix) . '\\\\Dock\\\\AltGenerator', $code);
}

/**
 * The first lines of every bundled PHP file: what it is, which version, and under which license.
 */
function withHeader(string $code, string $version): string
{
    $header = "/*\n"
        . ' * Part of dockcodes/dockaccess-alt-generator ' . $version . ", bundled by its bin/bundle.php.\n"
        . " *\n"
        . " * Copyright (c) 2026 Dock sp. z o.o.\n"
        . " * Released under the MIT License (MIT); the full text is in the LICENSE file of this bundle.\n"
        . " */\n";

    $code = preg_replace('/^<\?php\s*\n/', "<?php\n\n" . $header . "\n", $code, 1, $count);

    if ($count !== 1) {
        fail('A bundled file does not start with <?php.');
    }

    return (string) $code;
}

/**
 * @param list<string> $relatives
 *
 * @return list<string> Every directory that holds one of the files, the root as ''
 */
function directoriesOf(array $relatives): array
{
    $directories = [''];

    foreach ($relatives as $relative) {
        for ($directory = dirname($relative); $directory !== '.'; $directory = dirname($directory)) {
            $directories[] = $directory;
        }
    }

    $directories = array_values(array_unique($directories));
    sort($directories);

    return $directories;
}

/**
 * PrestaShop's directory index: nothing to list, the visitor goes one level up.
 */
function directoryIndex(): string
{
    return <<<'PHP'
<?php

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ../');
exit;

PHP;
}

function autoloader(string $prefix, string $guardPhp): string
{
    $template = <<<'PHP'
<?php

declare(strict_types=1);
%GUARD%
// Generated by bin/bundle.php of dockcodes/dockaccess-alt-generator. Loads the bundled classes.
\spl_autoload_register(static function (string $class): void {
    $prefix = '%NAMESPACE%';

    if (\strncmp($class, $prefix, \strlen($prefix)) !== 0) {
        return;
    }

    $relative = \substr($class, \strlen($prefix));

    if (\preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative) !== 1) {
        return;
    }

    $file = __DIR__ . '/src/' . \str_replace('\\', '/', $relative) . '.php';

    if (\is_file($file)) {
        require $file;
    }
});

PHP;

    // Inside a single-quoted PHP string, so every backslash is doubled.
    $namespace = str_replace('\\', '\\\\', $prefix . '\\' . NAMESPACE_ROOT . '\\');

    return str_replace(['%GUARD%', '%NAMESPACE%'], [$guardPhp, $namespace], $template);
}

/**
 * The target may be missing, empty, or an earlier bundle. Anything else is left alone.
 */
function checkTarget(string $target): void
{
    if (!file_exists($target)) {
        return;
    }

    if (!is_dir($target) || is_link($target)) {
        fail($target . ' is not a directory.');
    }

    if (array_diff((array) scandir($target), ['.', '..']) === []) {
        return;
    }

    $manifest = json_decode((string) @file_get_contents($target . '/bundle.json'), true);

    if (!is_array($manifest) || ($manifest['package'] ?? null) !== 'dockcodes/dockaccess-alt-generator') {
        fail($target . ' is not empty and holds no earlier bundle; refusing to overwrite it.');
    }
}

function removeTree(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }

    rmdir($directory);
}

/**
 * The bundle must parse and must not mention the bare namespace anywhere.
 *
 * @param list<string> $relatives
 */
function verify(string $target, array $relatives, string $prefix): void
{
    $quoted = preg_quote($prefix . '\\', '/');

    foreach ($relatives as $relative) {
        if (substr($relative, -4) !== '.php') {
            continue;
        }

        $path = $target . '/' . $relative;
        $code = (string) file_get_contents($path);

        if (preg_match('/(?<!' . $quoted . ')(?<![A-Za-z0-9_\\\\])Dock\\\\AltGenerator/', $code) === 1) {
            fail('An unprefixed reference is left in ' . $relative . '.');
        }

        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            fail('The bundled ' . $relative . " does not parse:\n" . implode("\n", $output));
        }
    }
}
