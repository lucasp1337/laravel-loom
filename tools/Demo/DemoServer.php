<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Demo;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Lucasp\Loom\Index\MetaField;
use Lucasp\Loom\LoomServiceProvider;
use Lucasp\Loom\Tools\Pages\DocsFixture;
use Orchestra\Testbench\Foundation\Application as Testbench;
use RuntimeException;

/**
 * Serves the browser UI over the docs fixture app, for the README demo
 * recording. `just demo-gif` runs `tools/demo-server.php` as the router of
 * PHP's built-in server; every request boots a fresh app.
 */
final readonly class DemoServer
{
    /** The `web` middleware group needs an app key; this one only guards a throwaway demo. */
    private const APP_KEY = 'base64:ZGVtby1kZW1vLWRlbW8tZGVtby1kZW1vLWRlbW8tZGU=';

    /** Pinned so the "last scanned" header is the same in every recording. */
    private const SCANNED_AT = '2026-01-01T00:00:00Z';

    public function __construct(private string $index) {}

    public function handle(Request $request): void
    {
        if (! is_file($this->index)) {
            $this->scan();
        }

        $app = Testbench::create(options: ['extra' => [
            'env' => [
                'APP_ENV=local',
                'APP_KEY='.self::APP_KEY,
                // Livewire posts back the CSRF token; a cookie session keeps it between requests.
                'SESSION_DRIVER=cookie',
                'LOOM_INDEX_PATH='.$this->index,
            ],
            'providers' => [McpServiceProvider::class, LivewireServiceProvider::class, LoomServiceProvider::class],
            'dont-discover' => ['*'],
        ]]);

        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    }

    /** Writes the fixture index once, before the first request reads it. */
    private function scan(): void
    {
        $fixture = new DocsFixture(dirname(__DIR__, 2));
        $index = json_decode((string) file_get_contents($fixture->indexPath()), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($index)) {
            throw new RuntimeException('The docs fixture index is not a JSON object');
        }
        $index[MetaField::SCANNED_AT->value] = self::SCANNED_AT;

        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->index));
        $files->put($this->index, json_encode($index, JSON_THROW_ON_ERROR));
    }
}
