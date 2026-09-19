<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Query\IndexSource;
use Lucasp\Loom\Query\IndexUnavailableException;

/**
 * Serves the index from a written `index.json`, reloading whenever the file's
 * mtime changes. Never scans: a missing snapshot is reported, not created.
 */
final class SnapshotIndexSource implements IndexSource
{
    private ?int $loadedMtime = null;

    private ?Index $index = null;

    public function __construct(
        private readonly IndexLoader $loader,
        private readonly string $path,
    ) {
    }

    public function index(): Index
    {
        $mtime = @filemtime($this->path);
        if ($mtime === false) {
            throw IndexUnavailableException::missing($this->path);
        }

        if ($this->index !== null && $mtime === $this->loadedMtime) {
            return $this->index;
        }

        try {
            $this->index = $this->loader->fromFile($this->path);
        } catch (IndexLoadException) {
            throw IndexUnavailableException::unloadable($this->path);
        }
        $this->loadedMtime = $mtime;

        return $this->index;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isAvailable(): bool
    {
        return is_file($this->path);
    }
}
