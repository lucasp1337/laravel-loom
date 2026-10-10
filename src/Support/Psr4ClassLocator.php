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

    public function locate(string $appRoot, string $fqcn): ?string
    {
        $this->maps[$appRoot] ??= ComposerPsr4Map::fromAppRoot($appRoot);

        return $this->maps[$appRoot]->locate($appRoot, $fqcn);
    }
}
