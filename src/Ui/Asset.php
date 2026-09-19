<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

/**
 * The only files the asset route will serve, from `resources/dist`.
 *
 * @internal
 */
enum Asset: string
{
    case CSS = 'loom.css';
    case JS = 'loom.js';
    case CYTOSCAPE = 'cytoscape.min.js';

    public function path(): string
    {
        return dirname(__DIR__, 2).'/resources/dist/'.$this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::CSS => 'text/css; charset=UTF-8',
            self::JS, self::CYTOSCAPE => 'application/javascript; charset=UTF-8',
        };
    }

    /** Short content hash, used as a cache-busting query on the asset URL; computed once per process. */
    public function version(): string
    {
        static $versions = [];

        return $versions[$this->value] ??= $this->hash();
    }

    private function hash(): string
    {
        $hash = @md5_file($this->path());

        return $hash === false ? '0' : substr($hash, 0, 8);
    }

    public function url(): string
    {
        return route('loom.assets', ['file' => $this->value, 'v' => $this->version()]);
    }
}
