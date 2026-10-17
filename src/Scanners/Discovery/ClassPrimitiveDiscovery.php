<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Illuminate\Support\Collection;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discover, seed, merge, emit for one class-based primitive: walk the
 * convention directory, add classes reached from dispatch sites and located
 * through PSR-4, and return the entries sorted by FQCN. Everything that
 * varies per primitive comes from the {@see ClassSpec}.
 *
 * A seed the directory walk already found is skipped; the rest must resolve
 * through PSR-4 to an admitted file declaring that FQCN. A seed reached only
 * through ambiguous forms must also pass {@see ClassSpec::admitsAmbiguous()}.
 *
 * A seed that cannot be located (a vendor class, a class outside the scan
 * directories, a missing file) is not dropped: each site that named it becomes
 * an `unresolved_dispatches` entry with reason `class_not_found`. A seed that
 * is only ambiguous is reported by the one spec that owns the fallback (jobs).
 *
 * A new class-based primitive is a spec plus a thin scanner. Primitives with
 * other discovery (listeners, observers, routes, schedule) keep their own
 * scanners.
 *
 * @internal
 */
final class ClassPrimitiveDiscovery
{
    use ScannerFilesystem;

    public function __construct(
        private readonly AstWalker $walker,
        private readonly Psr4ClassLocator $locator,
        ?ScanScope $scope = null,
    ) {
        $this->scope = $scope;
    }

    /**
     * @template TRecord of object
     * @template TLocation of object
     * @template TEntry of object
     *
     * @param  ClassSpec<TRecord, TLocation, TEntry>  $spec
     * @return DiscoveryResult<TEntry>
     */
    public function discover(string $appRoot, ClassSpec $spec): DiscoveryResult
    {
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker, $this->scope());

        $merged = $this->fromDirectory($appRoot, $spec, $resolver);
        $unresolved = [];

        foreach ($this->seeds($appRoot, $spec) as $fqcn => $seed) {
            // The convention directory already supplied it.
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locate($appRoot, $spec, $fqcn, $resolver);
            if ($located === null) {
                foreach ($seed['sites'] as $site) {
                    // An ambiguous site is reported by the one spec owning the fallback.
                    if ($site['ambiguous'] && ! $spec->reportsUnlocatableAmbiguous()) {
                        continue;
                    }

                    $unresolved[] = new UnresolvedDispatchEntry(
                        file: $site['file'],
                        line: $site['line'],
                        expression: $fqcn.'::class',
                        reason: UnresolvedReason::CLASS_NOT_FOUND->value,
                    );
                }

                continue;
            }

            // An ambiguous form is accepted only when the class proves it fits.
            if ($seed['ambiguous'] && ! $spec->admitsAmbiguous($located['location'], $this->isUnderDirectory($appRoot, $spec, $located['absolute']))) {
                continue;
            }

            $merged[$fqcn] = $located['location'];
        }

        $entries = [];
        foreach (Collection::make($merged)->sortKeys() as $fqcn => $location) {
            $entries[] = $spec->entryOf((string) $fqcn, $location);
        }

        return new DiscoveryResult($entries, $unresolved);
    }

    /**
     * @template TRecord of object
     * @template TLocation of object
     * @template TEntry of object
     *
     * @param  ClassSpec<TRecord, TLocation, TEntry>  $spec
     * @return array<string, TLocation>
     */
    private function fromDirectory(string $appRoot, ClassSpec $spec, ClassHierarchyResolver $resolver): array
    {
        $visitor = $spec->classVisitor();
        $found = [];

        foreach ($this->scanFiles($appRoot, $spec->directory()) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            // Later classes with the same FQCN overwrite earlier ones.
            foreach ($visitor->getClasses() as $record) {
                // A class the convention directory holds but that is not this primitive.
                if (! $spec->belongsByConvention($record, $resolver)) {
                    continue;
                }

                $found[$spec->fqcnOf($record)] = $spec->locationOf(
                    $record,
                    $this->relativePath($appRoot, $file->getPathname()),
                    $resolver,
                );
            }
        }

        return $found;
    }

    /**
     * Seeds merged by FQCN: a target stays ambiguous only while every site
     * that reached it was. Each site is kept for the unresolved report.
     *
     * @template TRecord of object
     * @template TLocation of object
     * @template TEntry of object
     *
     * @param  ClassSpec<TRecord, TLocation, TEntry>  $spec
     * @return array<string, array{ambiguous: bool, sites: list<array{file: string, line: int, ambiguous: bool}>}>
     */
    private function seeds(string $appRoot, ClassSpec $spec): array
    {
        $merged = [];

        foreach ($this->scanFiles($appRoot) as $file) {
            // Fresh per file: a failed parse skips beforeTraverse and would leak state.
            $visitors = $spec->seedVisitors();
            $this->walker->walk($file->getPathname(), $visitors);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($spec->seedsFrom($visitors) as $seed) {
                $current = $merged[$seed->fqcn] ?? ['ambiguous' => true, 'sites' => []];
                $current['ambiguous'] = $current['ambiguous'] && $seed->ambiguous;
                $current['sites'][] = ['file' => $relative, 'line' => $seed->line, 'ambiguous' => $seed->ambiguous];
                $merged[$seed->fqcn] = $current;
            }
        }

        return Collection::make($merged)->sortKeys()->all();
    }

    /**
     * Resolve $fqcn through PSR-4 to a class record in an admitted file.
     *
     * @template TRecord of object
     * @template TLocation of object
     * @template TEntry of object
     *
     * @param  ClassSpec<TRecord, TLocation, TEntry>  $spec
     * @return array{location: TLocation, absolute: string}|null
     */
    private function locate(string $appRoot, ClassSpec $spec, string $fqcn, ClassHierarchyResolver $resolver): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null || ! $this->scope()->admits($appRoot, $absolute)) {
            return null;
        }

        $visitor = $spec->classVisitor();
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $record) {
            if ($spec->fqcnOf($record) !== $fqcn) {
                continue;
            }

            return [
                'location' => $spec->locationOf($record, $this->relativePath($appRoot, $absolute), $resolver),
                'absolute' => $absolute,
            ];
        }

        return null;
    }

    /**
     * @param  ClassSpec<covariant object, covariant object, covariant object>  $spec
     */
    private function isUnderDirectory(string $appRoot, ClassSpec $spec, string $absolute): bool
    {
        return $this->scope()->isUnder($appRoot, $absolute, $spec->directory()->value);
    }
}
