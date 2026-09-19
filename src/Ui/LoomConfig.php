<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Illuminate\Contracts\Config\Repository;
use Lucasp\Loom\Query\ChainDepth;

/**
 * Typed access to the `loom.ui` config block.
 *
 * @internal
 */
final class LoomConfig
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('loom.ui.enabled', true);
    }

    /** @return list<string> */
    public function environments(): array
    {
        $environments = $this->config->get('loom.ui.environments', ['local']);

        return is_array($environments) ? array_values(array_filter($environments, is_string(...))) : ['local'];
    }

    public function allowInProduction(): bool
    {
        return $this->config->get('loom.ui.allow_in_production', false) === true;
    }

    /** Whether the UI may exist at all in the given app environment. */
    public function servesIn(string $environment): bool
    {
        return $this->enabled()
            && in_array($environment, $this->environments(), true)
            && ($environment !== 'production' || $this->allowInProduction());
    }

    /** True when production is listed but not allowed, so the UI stays off. */
    public function blockedProduction(string $environment): bool
    {
        return $this->enabled()
            && $environment === 'production'
            && in_array($environment, $this->environments(), true)
            && ! $this->allowInProduction();
    }

    public function path(): string
    {
        $path = $this->config->get('loom.ui.path', 'loom');
        $path = is_string($path) ? trim($path, '/') : '';

        return $path === '' ? 'loom' : $path;
    }

    public function domain(): ?string
    {
        $domain = $this->config->get('loom.ui.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /** @return list<string> */
    public function middleware(): array
    {
        $middleware = $this->config->get('loom.ui.middleware', ['web']);

        return is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : ['web'];
    }

    public function indexPath(): ?string
    {
        $path = $this->config->get('loom.ui.index_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function chainDepth(): int
    {
        $depth = $this->config->get('loom.ui.chain_depth', ChainDepth::DEFAULT);

        return self::clampDepth(is_int($depth) ? $depth : ChainDepth::DEFAULT);
    }

    public static function clampDepth(int $depth): int
    {
        return ChainDepth::clamp($depth);
    }
}
