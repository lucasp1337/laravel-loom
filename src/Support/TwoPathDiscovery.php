<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Closure;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;

/**
 * Shared "two-path discovery" primitives for class-style scanners.
 *
 * A scanner finds its primitive two ways: by walking a convention directory
 * (e.g. app/Jobs/) and by following dispatch-site targets resolved through
 * PSR-4. Both paths reduce to the same operation: parse one-or-more files
 * with a fresh class visitor and turn each discovered class record into a
 * scanner-specific location DTO keyed by FQCN.
 *
 * This trait owns that mechanical loop (is_dir guard, file iteration, AST
 * walk, FQCN matching, relative-path normalisation). The caller supplies the
 * parts that genuinely vary through hook closures: how the visitor is built,
 * how its records are read, how a record's FQCN is taken, and how a record
 * maps to a location DTO.
 *
 * Dispatch-site discovery itself is intentionally NOT shared here — its
 * visitor type and kind/ambiguity bookkeeping differ per scanner.
 *
 * Consuming scanners must also `use ScannerFilesystem`, which supplies the
 * iteratePhpFiles()/relativePath() helpers declared abstract below.
 */
trait TwoPathDiscovery
{
    abstract protected function walker(): AstWalker;

    abstract protected function psr4Locator(): Psr4ClassLocator;

    /**
     * Provided by {@see ScannerFilesystem}.
     *
     * @return iterable<SplFileInfo>
     */
    abstract protected function iteratePhpFiles(string $dir): iterable;

    /** Provided by {@see ScannerFilesystem}. */
    abstract protected function relativePath(string $appRoot, string $absolute): string;

    /**
     * Walk every PHP file under $directory with a fresh class visitor and map
     * each discovered class to a location DTO, keyed by FQCN. Later classes
     * with the same FQCN overwrite earlier ones (matches the prior inline
     * loops, which assigned unconditionally).
     *
     * @template TVisitor of NodeVisitorAbstract
     * @template TRecord of object
     * @template TLocation of object
     *
     * @param  Closure(): TVisitor  $makeVisitor
     * @param  Closure(TVisitor): list<TRecord>  $recordsOf
     * @param  Closure(TRecord): string  $fqcnOf
     * @param  Closure(TRecord, string $relativeFile): TLocation  $toLocation
     * @return array<string, TLocation>
     */
    protected function collectFromDirectory(
        string $appRoot,
        string $directory,
        Closure $makeVisitor,
        Closure $recordsOf,
        Closure $fqcnOf,
        Closure $toLocation,
    ): array {
        if (! is_dir($directory)) {
            return [];
        }

        $visitor = $makeVisitor();
        $results = [];

        foreach ($this->iteratePhpFiles($directory) as $file) {
            $this->walker()->walk($file->getPathname(), [$visitor]);

            foreach ($recordsOf($visitor) as $record) {
                $results[$fqcnOf($record)] = $toLocation(
                    $record,
                    $this->relativePath($appRoot, $file->getPathname()),
                );
            }
        }

        return $results;
    }

    /**
     * Resolve $fqcn to a file via PSR-4, parse it with a fresh class visitor
     * and map the matching class to a location DTO. Returns null when the file
     * cannot be located or holds no class with exactly that FQCN.
     *
     * @template TVisitor of NodeVisitorAbstract
     * @template TRecord of object
     * @template TLocation of object
     *
     * @param  Closure(): TVisitor  $makeVisitor
     * @param  Closure(TVisitor): list<TRecord>  $recordsOf
     * @param  Closure(TRecord): string  $fqcnOf
     * @param  Closure(TRecord, string $relativeFile): TLocation  $toLocation
     * @return TLocation|null
     */
    protected function locateClassByPsr4(
        string $appRoot,
        string $fqcn,
        Closure $makeVisitor,
        Closure $recordsOf,
        Closure $fqcnOf,
        Closure $toLocation,
    ): ?object {
        $absolute = $this->psr4Locator()->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = $makeVisitor();
        $this->walker()->walk($absolute, [$visitor]);

        foreach ($recordsOf($visitor) as $record) {
            if ($fqcnOf($record) !== $fqcn) {
                continue;
            }

            return $toLocation($record, $this->relativePath($appRoot, $absolute));
        }

        return null;
    }
}
