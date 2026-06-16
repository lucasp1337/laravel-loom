<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\IndexLoader;
use RuntimeException;

/**
 * Resolves the Loom index for the MCP server. Holds the snapshot path and the
 * auto-scan policy, lazily loading the index and reloading it whenever the file
 * changes on disk (watch-by-mtime — no background watcher needed since tool
 * calls are the only consumer). When auto-scan is on and the snapshot is
 * missing, it runs `loom:scan` once to produce it.
 */
final class IndexRepository
{
    private string $path;

    private bool $autoScan = true;

    private bool $scanned = false;

    private ?int $loadedMtime = null;

    private ?Index $index = null;

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function __construct(
        private readonly IndexLoader $loader,
        private readonly Artisan $artisan,
        string $defaultPath,
    ) {
        $this->path = $defaultPath;
    }

    /**
     * Point the repository at a specific snapshot. An explicit snapshot is used
     * as-is: auto-scan is disabled because the caller owns that file.
     */
    public function useSnapshot(string $path): void
    {
        $this->path = $path;
        $this->autoScan = false;
        $this->reset();
    }

    public function disableAutoScan(): void
    {
        $this->autoScan = false;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The typed read-model, reloaded if the snapshot changed since last access.
     */
    public function index(): Index
    {
        $this->refresh();

        if ($this->index === null) {
            throw new RuntimeException("Loom index could not be loaded from [{$this->path}].");
        }

        return $this->index;
    }

    /**
     * The raw decoded payload (schema-faithful snake_case), for tools that emit
     * index slices verbatim rather than re-deriving them from the read-model.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->refresh();

        return $this->payload ?? [];
    }

    private function refresh(): void
    {
        $this->ensureExists();

        $mtime = @filemtime($this->path);
        if ($mtime === false) {
            throw new RuntimeException("Loom index not found at [{$this->path}]. Run `php artisan loom:scan` first.");
        }

        if ($this->index !== null && $mtime === $this->loadedMtime) {
            return;
        }

        $raw = (string) file_get_contents($this->path);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->payload = $decoded;
        $this->index = $this->loader->fromJson($raw);
        $this->loadedMtime = $mtime;
    }

    private function ensureExists(): void
    {
        if (file_exists($this->path) || ! $this->autoScan || $this->scanned) {
            return;
        }

        // Auto-scan once: the scan command writes the default storage snapshot.
        $this->scanned = true;
        $this->artisan->call('loom:scan');
    }

    private function reset(): void
    {
        $this->index = null;
        $this->payload = null;
        $this->loadedMtime = null;
        $this->scanned = false;
    }
}
