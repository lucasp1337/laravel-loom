<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use JsonException;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\IndexLoadException;

/**
 * Serves the index from a written `index.json`, reloading whenever the file's
 * mtime or size changes. Never scans: a missing snapshot is reported, not
 * created. A failed reload keeps serving the last good index.
 *
 * @internal
 */
class SnapshotIndexSource implements IndexSource
{
    private ?string $loadedFingerprint = null;

    private ?Index $index = null;

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function __construct(
        private readonly IndexLoader $loader,
        protected string $path,
    ) {
    }

    public function index(): Index
    {
        $this->refresh();

        return $this->index ?? throw IndexUnavailableException::unloadable($this->path);
    }

    /**
     * The raw decoded document, for consumers that emit slices verbatim.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->refresh();

        return $this->payload ?? throw IndexUnavailableException::unloadable($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isAvailable(): bool
    {
        clearstatcache(true, $this->path);

        return is_file($this->path);
    }

    protected function reset(): void
    {
        $this->index = null;
        $this->payload = null;
        $this->loadedFingerprint = null;
    }

    private function refresh(): void
    {
        clearstatcache(true, $this->path);
        $mtime = @filemtime($this->path);
        $size = @filesize($this->path);
        if ($mtime === false || $size === false) {
            throw IndexUnavailableException::missing($this->path);
        }

        $fingerprint = $mtime.':'.$size;
        if ($this->index !== null && $fingerprint === $this->loadedFingerprint) {
            return;
        }

        try {
            [$index, $payload] = $this->load();
        } catch (IndexLoadException $e) {
            if ($this->index !== null) {
                return;
            }

            throw IndexUnavailableException::unloadable($this->path, $e->getMessage());
        }

        $this->index = $index;
        $this->payload = $payload;
        $this->loadedFingerprint = $fingerprint;
    }

    /**
     * @return array{0: Index, 1: array<string, mixed>}
     *
     * @throws IndexLoadException
     */
    private function load(): array
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw IndexLoadException::unreadable($this->path);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw IndexLoadException::invalidJson($e->getMessage());
        }

        if (! is_array($decoded)) {
            throw IndexLoadException::notAnObject();
        }

        /** @var array<string, mixed> $decoded */
        return [$this->loader->fromArray($decoded), $decoded];
    }
}
