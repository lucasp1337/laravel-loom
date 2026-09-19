<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Query\SnapshotIndexSource;

/**
 * The MCP server's index source: a snapshot source that, when auto-scan is on
 * and the snapshot is missing, runs `loom:scan` once to produce it.
 */
final class IndexRepository extends SnapshotIndexSource
{
    private bool $autoScan = true;

    private bool $scanned = false;

    public function __construct(
        IndexLoader $loader,
        private readonly Artisan $artisan,
        string $defaultPath,
    ) {
        parent::__construct($loader, $defaultPath);
    }

    /** An explicit snapshot is used as-is: the caller owns that file, so auto-scan is off. */
    public function useSnapshot(string $path): void
    {
        $this->path = $path;
        $this->autoScan = false;
        $this->reset();
        $this->scanned = false;
    }

    public function disableAutoScan(): void
    {
        $this->autoScan = false;
    }

    public function index(): Index
    {
        $this->ensureExists();

        return parent::index();
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $this->ensureExists();

        return parent::payload();
    }

    private function ensureExists(): void
    {
        if (! $this->autoScan || $this->scanned || $this->isAvailable()) {
            return;
        }

        $this->scanned = true;
        $this->artisan->call('loom:scan');
    }
}
