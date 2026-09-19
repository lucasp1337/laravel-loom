<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Contracts\Config\Repository;

/** Single source of truth for where the index snapshot lives. */
final class IndexPath
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $default,
    ) {
    }

    public function resolve(): string
    {
        foreach (['loom.ui.index_path', 'loom.index_path'] as $key) {
            $path = $this->config->get($key);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return $this->default;
    }
}
