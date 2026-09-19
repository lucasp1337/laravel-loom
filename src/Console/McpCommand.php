<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Laravel\Mcp\Server\Registrar;
use Lucasp\Loom\Mcp\IndexRepository;

/**
 * Starts the embedded Loom MCP server over stdio. Resolves the index first
 * (auto-scanning a missing snapshot unless `--no-scan`), then hands control to
 * the registered local server's stdio loop.
 */
final class McpCommand extends Command
{
    protected $signature = 'loom:mcp
        {--snapshot= : Path to an index.json to serve (default: storage/loom/index.json)}
        {--scan : Run a fresh loom:scan before serving}
        {--no-scan : Never auto-scan; require the snapshot to already exist}';

    protected $description = 'Start the embedded Loom MCP server over stdio';

    public function handle(Registrar $registrar, IndexRepository $repository): int
    {
        $snapshot = $this->option('snapshot');
        $hasSnapshot = is_string($snapshot) && $snapshot !== '';

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
