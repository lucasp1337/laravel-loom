<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Dto\SourceLocation;
use Lucasp\Loom\Scanners\Visitors\EventClassVisitor;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers event classes under app/Events/ plus targets reached from
 * statically resolvable dispatch sites elsewhere in app/.
 */
final class EventScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{events: list<EventEntry>}
     */
    public function scan(string $appRoot): array
    {
        $merged = $this->discoverFromFilesystem($appRoot);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot, $merged);

        foreach (array_keys($dispatchTargets) as $fqcn) {
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn);
            if ($located === null) {
                continue;
            }

            $merged[$fqcn] = $located;
        }

        return ['events' => $this->emit($merged)];
    }

    /**
     * @return array<string, SourceLocation>
     */
    private function discoverFromFilesystem(string $appRoot): array
    {
        $eventsDir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Events';
        if (! is_dir($eventsDir)) {
            return [];
        }

        $visitor = new EventClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($eventsDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $results[$class->fqcn] = new SourceLocation(
                    file: $this->relativePath($appRoot, $file->getPathname()),
                    line: $class->line,
                );
            }
        }

        return $results;
    }

    /**
     * @param  array<string, SourceLocation>  $fsClasses
     * @return array<string, null>
     */
    private function discoverFromDispatchSites(string $appRoot, array $fsClasses): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new EventDispatchSiteVisitor;
        /** @var array<string, bool> $candidates true when seen via an unambiguous form */
        $candidates = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getTargets() as $target) {
                $isUnambiguous = $target['form'] !== 'dispatchable';
                if (! isset($candidates[$target['fqcn']])) {
                    $candidates[$target['fqcn']] = $isUnambiguous;
                } elseif ($isUnambiguous) {
                    $candidates[$target['fqcn']] = true;
                }
            }
        }

        $kept = [];
        foreach ($candidates as $fqcn => $unambiguous) {
            if (isset($fsClasses[$fqcn])) {
                $kept[$fqcn] = null;

                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn);
            if ($located === null) {
                continue;
            }

            // Dispatchable form is ambiguous with jobs — accept only when the
            // resolved file is under app/Events/.
            if ($unambiguous || str_starts_with($located->file, 'app/Events/')) {
                $kept[$fqcn] = null;
            }
        }

        return $kept;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?SourceLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new EventClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new SourceLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
            );
        }

        return null;
    }

    /**
     * @param  array<string, SourceLocation>  $merged
     * @return list<EventEntry>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = new EventEntry(
                id: $fqcn,
                fqcn: $fqcn,
                file: $location->file,
                line: $location->line,
            );
        }

        return $entries;
    }
}
