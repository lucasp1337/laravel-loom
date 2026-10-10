<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Illuminate\Support\Collection;
use Lucasp\Loom\Support\AstWalker;
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
     * @return list<TEntry>
     */
    public function discover(string $appRoot, ClassSpec $spec): array
    {
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker, $this->scope());

        $merged = $this->fromDirectory($appRoot, $spec, $resolver);

        foreach ($this->seeds($appRoot, $spec) as $seed) {
            // The convention directory already supplied it.
            if (isset($merged[$seed->fqcn])) {
                continue;
            }

            $located = $this->locate($appRoot, $spec, $seed->fqcn, $resolver);
            if ($located === null) {
                continue;
            }

            // An ambiguous form is accepted only when the class proves it fits.
            if ($seed->ambiguous && ! $spec->admitsAmbiguous($located['location'], $this->isUnderDirectory($appRoot, $spec, $located['absolute']))) {
                continue;
            }

            $merged[$seed->fqcn] = $located['location'];
        }

        $entries = [];
        foreach (Collection::make($merged)->sortKeys() as $fqcn => $location) {
            $entries[] = $spec->entryOf((string) $fqcn, $location);
        }

        return $entries;
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
     * that reached it was.
     *
     * @template TRecord of object
     * @template TLocation of object
     * @template TEntry of object
     *
     * @param  ClassSpec<TRecord, TLocation, TEntry>  $spec
     * @return list<DispatchSeed>
     */
    private function seeds(string $appRoot, ClassSpec $spec): array
    {
        /** @var array<string, bool> $ambiguousByFqcn */
        $ambiguousByFqcn = [];

        foreach ($this->scanFiles($appRoot) as $file) {
            // Fresh per file: a failed parse skips beforeTraverse and would leak state.
            $visitors = $spec->seedVisitors();
            $this->walker->walk($file->getPathname(), $visitors);

            foreach ($spec->seedsFrom($visitors) as $seed) {
                $ambiguousByFqcn[$seed->fqcn] = ($ambiguousByFqcn[$seed->fqcn] ?? true) && $seed->ambiguous;
            }
        }

        $merged = [];
        foreach ($ambiguousByFqcn as $fqcn => $ambiguous) {
            $merged[] = new DispatchSeed((string) $fqcn, $ambiguous);
        }

        return $merged;
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
