<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Dto\UnresolvedRoutePath;
use Lucasp\Loom\Scanners\Visitors\ProviderListVisitor;
use Lucasp\Loom\Scanners\Visitors\RouteFileLoadVisitor;
use SplFileInfo;

/**
 * The route files a scan reads: `scan.route_paths` plus, unless
 * `scan.discover_routes` is off, every file that a provider, a route file or
 * `bootstrap/app.php` loads by a statically resolvable path, minus
 * `scan.exclude`. Paths that cannot be followed are kept for reporting.
 *
 * Call sites are looked for in the scan directories, the route files,
 * `bootstrap/app.php` and the providers listed in `bootstrap/providers.php`
 * and `config/app.php`, and discovered files are searched in turn.
 *
 * @internal
 */
final class RouteFileDiscovery
{
    /** Cheap pre-filter: a file lacking all of these cannot hold a loading call. */
    private const NEEDLES = ['loadRoutesFrom', 'group', 'withRouting', 'withCommands'];

    /** @var array<string, array{files: list<string>, unresolved: list<UnresolvedRoutePath>}> */
    private array $results = [];

    private RoutePathResolver $resolver;

    public function __construct(
        private readonly ScanScope $scope,
        private readonly AstWalker $walker,
        private readonly Psr4ClassLocator $locator = new Psr4ClassLocator,
    ) {
        $this->resolver = new RoutePathResolver;
    }

    /**
     * @return list<SplFileInfo>
     */
    public function files(string $appRoot): array
    {
        return array_map(
            fn (string $path): SplFileInfo => new SplFileInfo($path),
            $this->result($appRoot)['files'],
        );
    }

    /**
     * Paths that were seen but not followed, sorted by file and line.
     *
     * @return list<UnresolvedRoutePath>
     */
    public function unresolved(string $appRoot): array
    {
        return $this->result($appRoot)['unresolved'];
    }

    /**
     * @return array{files: list<string>, unresolved: list<UnresolvedRoutePath>}
     */
    private function result(string $appRoot): array
    {
        return $this->results[$appRoot] ??= $this->discover($appRoot);
    }

    /**
     * @return array{files: list<string>, unresolved: list<UnresolvedRoutePath>}
     */
    private function discover(string $appRoot): array
    {
        $files = [];
        foreach ($this->scope->routeFiles($appRoot) as $file) {
            $files[$file->getPathname()] = true;
        }

        if (! $this->scope->discoversRoutes()) {
            return ['files' => array_keys($files), 'unresolved' => []];
        }

        $unresolved = [];
        $queue = [];
        $queued = [];
        $enqueue = function (string $path) use (&$queue, &$queued, $appRoot): void {
            if (isset($queued[$path]) || $this->scope->isExcluded($appRoot, $path)) {
                return;
            }
            $queued[$path] = true;
            $queue[] = $path;
        };

        foreach ($this->scope->files($appRoot) as $file) {
            $enqueue($file->getPathname());
        }
        foreach (array_keys($files) as $path) {
            $enqueue($path);
        }
        $bootstrap = Str::rtrim($appRoot, '/\\').DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (is_file($bootstrap)) {
            $enqueue($bootstrap);
        }
        foreach ($this->providerFiles($appRoot) as $path) {
            $enqueue($path);
        }

        for ($i = 0; $i < count($queue); $i++) {
            foreach ($this->references($queue[$i]) as $reference) {
                $outcome = $this->follow($reference, $queue[$i], $appRoot);

                if ($outcome instanceof UnresolvedRoutePath) {
                    $unresolved[] = $outcome;
                } elseif ($outcome !== null && ! $this->scope->isExcluded($appRoot, $outcome)) {
                    $files[$outcome] = true;
                    $enqueue($outcome);
                }
            }
        }

        usort($unresolved, fn (UnresolvedRoutePath $a, UnresolvedRoutePath $b): int => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return ['files' => array_keys($files), 'unresolved' => $unresolved];
    }

    /**
     * An existing file path to read, a problem to report, or null when the
     * reference is legitimately not a route file (a command directory).
     */
    private function follow(RouteFileReference $reference, string $sourceFile, string $appRoot): string|UnresolvedRoutePath|null
    {
        $resolved = $this->resolver->resolve($reference, $sourceFile, $appRoot);

        if ($resolved instanceof RoutePathProblem) {
            return new UnresolvedRoutePath($sourceFile, $reference->line, $reference->loader, $resolved);
        }
        if (is_file($resolved)) {
            return $resolved;
        }
        if ($reference->allowsDirectory && is_dir($resolved)) {
            return null;
        }

        return new UnresolvedRoutePath($sourceFile, $reference->line, $reference->loader, RoutePathProblem::NOT_FOUND);
    }

    /**
     * @return list<RouteFileReference>
     */
    private function references(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false || ! Str::contains($source, self::NEEDLES)) {
            return [];
        }

        $visitor = new RouteFileLoadVisitor;
        if ($this->walker->walk($file, [$visitor]) === null) {
            return [];
        }

        return $visitor->getReferences();
    }

    /**
     * Provider files named by `bootstrap/providers.php` and `config/app.php`,
     * located through the project's PSR-4 map.
     *
     * @return list<string>
     */
    private function providerFiles(string $appRoot): array
    {
        $root = Str::rtrim($appRoot, '/\\');
        $root = $root.DIRECTORY_SEPARATOR;
        $found = [];

        foreach (['bootstrap/providers.php', 'config/app.php'] as $list) {
            $file = $root.Str::replace('/', DIRECTORY_SEPARATOR, $list);
            if (! is_file($file) || $this->scope->isExcluded($appRoot, $file)) {
                continue;
            }

            $visitor = new ProviderListVisitor;
            if ($this->walker->walk($file, [$visitor]) === null) {
                continue;
            }

            foreach ($visitor->getProviders() as $fqcn) {
                $path = $this->locator->locate($appRoot, $fqcn);
                if ($path !== null && Str::startsWith($path, $root)) {
                    $found[$path] = true;
                }
            }
        }

        return array_keys($found);
    }
}
