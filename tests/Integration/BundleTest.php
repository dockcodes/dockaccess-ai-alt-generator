<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Integration;

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Tests\Support\Images;
use PHPUnit\Framework\TestCase;

/**
 * bin/bundle.php, as a plugin build runs it: the bundle has to work on its own, under its prefix,
 * without a single class of the original namespace.
 *
 * @requires extension gd
 */
final class BundleTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dkag-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testTheBundleGeneratesUnderItsPrefix(): void
    {
        $target = $this->dir . '/vendor/dockaccess';
        $this->bundle($target, 'Acme\\Plugin\\Vendor', '--guard=ACME_PLUGIN');

        file_put_contents($this->dir . '/image.jpg', Images::jpegWithMetadata());
        $script = <<<'PHP'
define('ACME_PLUGIN', true);
require $argv[1] . '/autoload.php';

use Acme\Plugin\Vendor\Dock\AltGenerator as Core;

$transport = new class implements Core\Http\TransportInterface {
    public $sent;
    public function send(Core\Http\Request $request): Core\Http\Response
    {
        $this->sent = $request;
        return new Core\Http\Response(200, ['content-type' => 'application/json'], json_encode(['data' => [
            'set_uuid' => '11111111-2222-4333-8444-555555555555',
            'image' => ['uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'alts' => [['alt' => 'A bundled alt', 'locale' => 'en']]],
        ]]));
    }
};

$generator = new Core\AltGenerator(Core\Config::create('BundleKey0123456789'), $transport);
$result    = $generator->generate(Core\Image\ImageSource::fromFile($argv[2], [dirname($argv[2])]));

echo json_encode([
    'alt'      => $result->alt()->text(),
    'method'   => $result->image()->method(),
    'secret'   => strpos($transport->sent->body(), $argv[3]) !== false,
    'original' => class_exists('Dock\AltGenerator\AltGenerator', false),
]);
PHP;

        $output = $this->php(['-r', $script, $target, $this->dir . '/image.jpg', Images::SECRET]);

        self::assertSame(['alt' => 'A bundled alt', 'method' => 'reencoded', 'secret' => false, 'original' => false], json_decode($output, true));
    }

    public function testTheGuardStopsDirectAccess(): void
    {
        $target = $this->dir . '/bundle';
        $this->bundle($target, 'Acme\\Guarded', '--guard=ACME_GUARD');

        foreach (['autoload.php', 'src/AltGenerator.php', 'src/Image/ImageInspector.php'] as $file) {
            self::assertSame('', $this->php([$target . '/' . $file]), $file . ' ran without the guard constant.');
        }
    }

    public function testTheManifestMatchesTheFiles(): void
    {
        $target = $this->dir . '/bundle';
        $this->bundle($target, 'Acme\\Checked');

        $manifest = json_decode((string) file_get_contents($target . '/bundle.json'), true);

        self::assertSame(AltGenerator::VERSION, $manifest['version']);
        self::assertSame('Acme\\Checked\\Dock\\AltGenerator', $manifest['prefix']);
        self::assertArrayHasKey('LICENSE', $manifest['files']);

        foreach ($manifest['files'] as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $target . '/' . $file), $file);
        }
    }

    public function testEveryPhpFileNamesThePackageAndItsLicense(): void
    {
        $target = $this->dir . '/bundle';
        $this->bundle($target, 'Acme\\Licensed', '--guard=ACME_GUARD');

        $manifest = json_decode((string) file_get_contents($target . '/bundle.json'), true);

        foreach (array_keys($manifest['files']) as $file) {
            if (substr($file, -4) !== '.php') {
                continue;
            }

            $head = substr((string) file_get_contents($target . '/' . $file), 0, 400);

            self::assertStringStartsWith("<?php\n\n/*\n * Part of dockcodes/dockaccess-alt-generator " . AltGenerator::VERSION, $head, $file);
            self::assertStringContainsString('MIT License (MIT)', $head, $file);
        }
    }

    public function testIndexPutsARedirectIntoEveryDirectory(): void
    {
        $target = $this->dir . '/bundle';
        $this->bundle($target, 'Acme\\Indexed', '--guard=ACME_GUARD', '--index');

        $manifest = json_decode((string) file_get_contents($target . '/bundle.json'), true);

        foreach (['index.php', 'src/index.php', 'src/Exception/index.php', 'src/Image/index.php'] as $file) {
            self::assertArrayHasKey($file, $manifest['files']);
            self::assertStringContainsString("header('Location: ../');", (string) file_get_contents($target . '/' . $file), $file);
        }

        $directories = (int) trim((string) shell_exec('find ' . escapeshellarg($target) . ' -type d | wc -l'));
        $indexes     = (int) trim((string) shell_exec('find ' . escapeshellarg($target) . ' -name index.php | wc -l'));

        self::assertSame($directories, $indexes);
    }

    public function testItNeverOverwritesADirectoryItDidNotWrite(): void
    {
        $target = $this->dir . '/plugin';
        mkdir($target);
        file_put_contents($target . '/plugin.php', '<?php // the plugin itself');

        exec($this->command([dirname(__DIR__, 2) . '/bin/bundle.php', $target, 'Acme\\Plugin']) . ' 2>&1', $output, $status);

        self::assertSame(1, $status);
        self::assertSame('<?php // the plugin itself', file_get_contents($target . '/plugin.php'));
    }

    private function bundle(string $target, string $prefix, string ...$options): void
    {
        exec($this->command(array_merge([dirname(__DIR__, 2) . '/bin/bundle.php', $target, $prefix], $options)) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    /**
     * @param list<string> $arguments
     */
    private function php(array $arguments): string
    {
        exec($this->command($arguments) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));

        return implode("\n", $output);
    }

    /**
     * @param list<string> $arguments
     */
    private function command(array $arguments): string
    {
        return implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $arguments)));
    }
}
