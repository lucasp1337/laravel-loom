<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Dto\UnresolvedGroupAttribute;
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

    /** @var array<string, array{files: list<string>, unresolved: list<UnresolvedRoutePath>, contexts: array<string, list<RouteGroupContext>>, attributes: list<UnresolvedGroupAttribute>}> */
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
        return array_values(Arr::map($this->result($appRoot)['files'], fn (string $path): SplFileInfo => new SplFileInfo($path)));
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
     * The group contexts a discovered file is loaded under, one per distinct
     * loading path. Empty for files nothing loads, which Laravel would
     * register ungrouped.
     *
     * @return list<RouteGroupContext>
     */
    public function contexts(string $appRoot, string $file): array
    {
        return $this->result($appRoot)['contexts'][$file] ?? [];
    }

    /**
     * Group attributes at a loading call that could not be resolved, sorted by
     * file and line.
     *
     * @return list<UnresolvedGroupAttribute>
     */
    public function unresolvedAttributes(string $appRoot): array
    {
        return $this->result($appRoot)['attributes'];
    }

    /**
     * @return array{files: list<string>, unresolved: list<UnresolvedRoutePath>, contexts: array<string, list<RouteGroupContext>>, attributes: list<UnresolvedGroupAttribute>}
     */
    private function result(string $appRoot): array
    {
        return $this->results[$appRoot] ??= $this->discover($appRoot);
    }

    /**
     * @return array{files: list<string>, unresolved: list<UnresolvedRoutePath>, contexts: array<string, list<RouteGroupContext>>, attributes: list<UnresolvedGroupAttribute>}
     */
    private function discover(string $appRoot): array
    {
        $files = [];
        foreach ($this->scope->routeFiles($appRoot) as $file) {
            $files[$file->getPathname()] = true;
        }

        if (! $this->scope->discoversRoutes()) {
            return ['files' => array_keys($files), 'unresolved' => [], 'contexts' => [], 'attributes' => []];
        }

        $unresolved = [];
        $attributes = [];
        $loads = [];
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

                // a path that could not be followed: kept for reporting
                if ($outcome instanceof UnresolvedRoutePath) {
                    $unresolved[] = $outcome;

                    continue;
                }

                // a command directory, or a file the scan excludes: not a route file to read
                if ($outcome === null || $this->scope->isExcluded($appRoot, $outcome)) {
                    continue;
                }

                $files[$outcome] = true;
                $enqueue($outcome);
                $loads[$queue[$i]][] = ['file' => $outcome, 'context' => $reference->context];

                foreach ($this->unresolvedAttributesOf($reference, $queue[$i]) as $attribute) {
                    $attributes[] = $attribute;
                }
            }
        }

        $unresolved = array_values(collect($unresolved)->sort(fn (UnresolvedRoutePath $a, UnresolvedRoutePath $b): int => [$a->file, $a->line] <=> [$b->file, $b->line])->all());

        $attributes = array_values(collect($attributes)->sort(fn (UnresolvedGroupAttribute $a, UnresolvedGroupAttribute $b): int => [$a->file, $a->line, $a->attribute->value] <=> [$b->file, $b->line, $b->attribute->value])->all());

        return [
            'files' => array_keys($files),
            'unresolved' => $unresolved,
            'contexts' => $this->composeContexts($loads),
            'attributes' => $attributes,
        ];
    }

    /**
     * Push each loading call's context down the load graph. A file nothing
     * loads is a root with the empty context; a loaded file is wrapped by every
     * context of every file that loads it. A file already on the current path
     * is not followed again, so a load cycle cannot grow a context forever.
     *
     * @param  array<string, list<array{file: string, context: RouteGroupContext}>>  $loads  by loading file
     * @return array<string, list<RouteGroupContext>> resolved contexts by loaded file, de-duplicated
     */
    private function composeContexts(array $loads): array
    {
        $loaded = [];
        foreach ($loads as $edges) {
            foreach ($edges as $edge) {
                $loaded[$edge['file']] = true;
            }
        }

        $contexts = [];
        foreach (array_keys($loads) as $file) {
            if (! isset($loaded[$file])) {
                $this->pushContexts($loads, $file, RouteGroupContext::empty(), [$file], $contexts);
            }
        }

        return Arr::map($contexts, static fn (array $list): array => array_values($list));
    }

    /**
     * @param  array<string, list<array{file: string, context: RouteGroupContext}>>  $loads
     * @param  list<string>  $path  files on the way to $file, to stop load cycles
     * @param  array<string, array<string, RouteGroupContext>>  $contexts  by loaded file, then context key
     */
    private function pushContexts(array $loads, string $file, RouteGroupContext $context, array $path, array &$contexts): void
    {
        foreach ($loads[$file] ?? [] as $edge) {
            if (collect($path)->containsStrict($edge['file'])) {
                continue;
            }

            $inherited = $edge['context']->within($context)->resolved();
            $contexts[$edge['file']][$inherited->key()] = $inherited;
            $this->pushContexts($loads, $edge['file'], $inherited, [...$path, $edge['file']], $contexts);
        }
    }

    /**
     * The group attributes of a loading call that were not literals, one entry
     * per attribute however many enclosing groups set it.
     *
     * @return list<UnresolvedGroupAttribute>
     */
    private function unresolvedAttributesOf(RouteFileReference $reference, string $sourceFile): array
    {
        return array_values(collect($reference->context->unresolved)
            ->unique(fn (RouteGroupAttribute $attribute): string => $attribute->value)
            ->map(fn (RouteGroupAttribute $attribute): UnresolvedGroupAttribute => new UnresolvedGroupAttribute($sourceFile, $reference->line, $reference->loader, $attribute))
            ->all());
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
