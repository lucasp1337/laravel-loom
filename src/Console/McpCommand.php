<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Laravel\Mcp\Server\Registrar;
use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Support\OptionalPackage;
use Lucasp\Loom\Support\OptionalPackages;

/**
 * Starts the embedded Loom MCP server over stdio. Resolves the index first
 * (auto-scanning a missing snapshot unless `--no-scan`), then hands control to
 * the registered local server's stdio loop.
 *
 * @internal
 */
final class McpCommand extends Command
{
    use ReadsCommandInput;

    protected $signature = 'loom:mcp
        {--snapshot= : Path to an index.json to serve (default: storage/loom/index.json)}
        {--scan : Run a fresh loom:scan before serving}
        {--no-scan : Never auto-scan; require the snapshot to already exist}';

    protected $description = 'Start the embedded Loom MCP server over stdio';

    public function handle(OptionalPackages $packages): int
    {
        if (! $packages->has(OptionalPackage::MCP)) {
            $this->components->error(OptionalPackage::MCP->installHint());

            return self::FAILURE;
        }

        if (! (bool) config('loom.mcp.enabled', true)) {
            $this->components->error('The Loom MCP server is disabled (loom.mcp.enabled is false).');

            return self::FAILURE;
        }

        // Resolved after the guard: these types need laravel/mcp.
        $repository = $this->laravel->make(IndexRepository::class);
        $registrar = $this->laravel->make(Registrar::class);

        $snapshot = $this->stringOpt('snapshot');
        $hasSnapshot = $snapshot !== '';

        if ($hasSnapshot && $this->option('scan')) {
            $this->components->error('--scan writes the default index and cannot be combined with --snapshot.');

            return self::FAILURE;
        }

        if ($hasSnapshot) {
            $repository->useSnapshot($snapshot);
        }

        if ($this->option('no-scan')) {
            $repository->disableAutoScan();
        }

        // Silent: anything on stdout before the server starts would corrupt the JSON-RPC stream.
        if ($this->option('scan') && $this->callSilently('loom:scan') !== self::SUCCESS) {
            $this->components->error('loom:scan failed; not starting the MCP server.');

            return self::FAILURE;
        }

        if ($this->option('no-scan') && ! $repository->isAvailable()) {
            $this->components->error("No index at [{$repository->path()}] and --no-scan is set. Run `php artisan loom:scan` first.");

            return self::FAILURE;
        }

        $server = $registrar->getLocalServer('loom');
        if ($server === null) {
            $this->components->error('Loom MCP server is not registered.');

            return self::FAILURE;
        }

        // Hands off to the stdio transport loop (reads stdin, writes stdout).
        $server();

        return self::SUCCESS;
    }
}
