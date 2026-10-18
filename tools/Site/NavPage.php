<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Site;

use Illuminate\Support\Str;

/** One page of the site nav: its top-level section and its path under the docs directory. */
final readonly class NavPage
{
    public function __construct(
        public string $section,
        public string $path,
    ) {}

    /** The page's address on the built site, which serves `x/y.md` at `x/y/` and an index or README at its directory. */
    public function url(string $siteUrl): string
    {
        $directory = Str::of($this->path)->beforeLast('/')->value();
        $name = Str::of($this->path)->afterLast('/')->beforeLast('.md')->value();
        $base = str_contains($this->path, '/') ? "{$directory}/" : '';

        return rtrim($siteUrl, '/').'/'.(in_array($name, ['index', 'README'], true) ? $base : "{$base}{$name}/");
    }
}
