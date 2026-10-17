<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Map an FQCN to its on-disk file using the PSR-4 autoload mappings of the
 * scanned app's composer.json (Laravel's `App\` → `app/` when it declares
 * none). Pure path lookup — caller is responsible for parsing the file.
 *
 * @internal
 */
final class Psr4ClassLocator
{
    /** @var array<string, ComposerPsr4Map> */
    private array $maps = [];

    /** @param  list<string>  $composerFiles  extra composer.json globs (`scan.composer_files`) */
    public function __construct(private readonly array $composerFiles = []) {}

    public function locate(string $appRoot, string $fqcn): ?string
    {
        $this->maps[$appRoot] ??= ComposerPsr4Map::fromAppRoot($appRoot, $this->composerFiles);

        return $this->maps[$appRoot]->locate($appRoot, $fqcn);
    }
}
