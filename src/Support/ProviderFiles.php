<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;
use Lucasp\Loom\Scanners\Visitors\ProviderListVisitor;
use Lucasp\Loom\Support\Ast\AstWalker;

/**
 * The service provider files an app registers: the classes listed in
 * `bootstrap/providers.php` and the `providers` key of `config/app.php`,
 * located through the PSR-4 map. Only files inside the app root that no
 * exclude glob matches are kept, wherever the scan paths point.
 *
 * @internal
 */
final class ProviderFiles
{
    private const LISTS = ['bootstrap/providers.php', 'config/app.php'];

    /** @var array<string, list<string>> */
    private array $files = [];

    public function __construct(
        private readonly ScanScope $scope,
        private readonly AstWalker $walker,
        private readonly Psr4ClassLocator $locator = new Psr4ClassLocator,
    ) {}

    /**
     * Absolute provider file paths, in list order, without duplicates.
     *
     * @return list<string>
     */
    public function all(string $appRoot): array
    {
        return $this->files[$appRoot] ??= $this->locate($appRoot);
    }

    /**
     * The provider files no scan directory holds.
     *
     * @return list<string>
     */
    public function outsideScanPaths(string $appRoot): array
    {
        return array_values(collect($this->all($appRoot))->reject(fn (string $path): bool => $this->scope->admits($appRoot, $path))->all());
    }

    /**
     * @return list<string>
     */
    private function locate(string $appRoot): array
    {
        $root = AppPath::prefix($appRoot);
        $found = [];

        foreach (self::LISTS as $list) {
            $file = AppPath::join($appRoot, $list);
            if (! is_file($file) || $this->scope->isExcluded($appRoot, $file)) {
                continue;
            }

            $visitor = new ProviderListVisitor;
            if ($this->walker->walk($file, [$visitor]) === null) {
                continue;
            }

            foreach ($visitor->getProviders() as $fqcn) {
                $path = $this->locator->locate($appRoot, $fqcn);
                if ($path !== null && Str::startsWith($path, $root) && ! $this->scope->isExcluded($appRoot, $path)) {
                    $found[$path] = true;
                }
            }
        }

        return array_keys($found);
    }
}
