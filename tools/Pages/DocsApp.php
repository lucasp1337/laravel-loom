<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Lucasp\Loom\LoomServiceProvider;
use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Support\IndexPath;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the real Artisan commands against copies of the docs fixture app, so
 * documented command output is printed by Loom, never pasted. The base app is
 * {@see DocsFixture::APP}; the branch app is the base plus {@see self::BRANCH}.
 * The temporary app root prints as {@see self::DISPLAY_ROOT}.
 */
final class DocsApp
{
    public const BRANCH = 'tests/Fixtures/docs-order-branch';

    public const DISPLAY_ROOT = '/var/www/shop';

    private ?Application $app = null;

    /** @var array<string, string> app name => temporary root */
    private array $roots = [];

    public function __construct(private readonly string $root) {}

    public function __destruct()
    {
        foreach ($this->roots as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    /** Root of the base app, copied once; scanning it writes its default index. */
    public function base(): string
    {
        return $this->roots['base'] ??= $this->copy([DocsFixture::APP]);
    }

    /** Root of the branch app: the base plus the branch overlay. */
    public function branch(): string
    {
        return $this->roots['branch'] ??= $this->copy([DocsFixture::APP, self::BRANCH]);
    }

    /** Root of an app with no files, for the "no index yet" errors. */
    public function empty(): string
    {
        return $this->roots['empty'] ??= $this->copy([]);
    }

    /**
     * Runs one command with the given app as the base path; returns what it
     * printed, with the temporary root shown as {@see self::DISPLAY_ROOT}.
     *
     * @param  array<string, mixed>  $parameters
     * @param  OutputInterface::VERBOSITY_*  $verbosity
     */
    public function run(string $appRoot, string $command, array $parameters = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
    {
        $app = $this->app();
        $app->setBasePath($appRoot);
        // Both singletons capture the storage path of the app they were first built for.
        $app->forgetInstance(IndexPath::class);
        $app->forgetInstance(IndexRepository::class);
        $output = new BufferedOutput($verbosity);
        $app->make(Kernel::class)->call($command, $parameters, $output);

        return Str::of($output->fetch())
            ->replace($appRoot, self::DISPLAY_ROOT)
            // Colours are for a terminal; a page shows the plain text.
            ->replaceMatches('/\e\[[0-9;]*m/', '')
            ->trim()
            ->value();
    }

    /** Scans an app once and returns the path of the index it wrote. */
    public function scanned(string $appRoot): string
    {
        $index = $appRoot.'/storage/loom/index.json';
        if (! is_file($index)) {
            $this->run($appRoot, 'loom:scan');
        }

        return $index;
    }

    private function app(): Application
    {
        if ($this->app === null) {
            $this->app = Testbench::create(options: ['extra' => [
                'providers' => [McpServiceProvider::class, LivewireServiceProvider::class, LoomServiceProvider::class],
                'dont-discover' => ['*'],
            ]]);
            // Booting installs global error and exception handlers; the caller keeps its own.
            restore_error_handler();
            restore_exception_handler();
        }

        return $this->app;
    }

    /** @param  list<string>  $layers  fixture directories copied in order, later ones on top */
    private function copy(array $layers): string
    {
        $files = new Filesystem;
        $directory = sys_get_temp_dir().'/loom-docs-app-'.bin2hex(random_bytes(6));
        $files->ensureDirectoryExists($directory);
        foreach ($layers as $layer) {
            $files->copyDirectory($this->root.'/'.$layer, $directory);
        }

        return $directory;
    }
}
