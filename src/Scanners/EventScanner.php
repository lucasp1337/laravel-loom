<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\EventClassVisitor;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers event classes via a filesystem walk of app/Events/ seeded by
 * statically resolvable dispatch sites across app/. See
 * docs/scanners/events.md for the full design.
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
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array
    {
        $fsClasses = $this->discoverFromFilesystem($appRoot);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot, $fsClasses);

        $merged = $fsClasses;

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
     * Filesystem walk of app/Events/ — extract every top-level class.
     *
     * @return array<string, array{file: string, line: int}>
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
                $relative = $this->relativePath($appRoot, $file->getPathname());
                $results[$class['fqcn']] = [
                    'file' => $relative,
                    'line' => $class['line'],
                ];
            }
        }

        return $results;
    }

    /**
     * Walk all of app/, collect dispatch-site targets, filter to ones that
     * either map to a known filesystem class or to a viable PSR-4 path
     * under app/Events/.
     *
     * @param  array<string, array{file: string, line: int}>  $fsClasses
     * @return array<string, null>
     */
    private function discoverFromDispatchSites(string $appRoot, array $fsClasses): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new EventDispatchSiteVisitor;
        /** @var array<string, bool> $candidates FQCN => true if seen via helper/facade (unambiguous) */
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
            // Already known via filesystem walk under app/Events/ — keep.
            if (isset($fsClasses[$fqcn])) {
                $kept[$fqcn] = null;

                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn);
            if ($located === null) {
                continue;
            }

            // Helper (event(...)) and facade (Event::dispatch(...))
            // forms are unambiguous event dispatches and bring the target in
            // regardless of file location. The Dispatchable form
            // (X::dispatch(...)) is ambiguous (could be a job) so it is only
            // accepted when the resolved file lives under app/Events/.
            if ($unambiguous || str_starts_with($located['file'], 'app/Events/')) {
                $kept[$fqcn] = null;
            }
        }

        return $kept;
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new EventClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        $class = AstHelpers::findClass($visitor->getClasses(), $fqcn);
        if ($class === null) {
            return null;
        }

        return [
            'file' => $this->relativePath($appRoot, $absolute),
            'line' => $class['line'],
        ];
    }

    /**
     * Build schema-shaped entries, sorted by FQCN.
     *
     * @param  array<string, array{file: string, line: int}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = [
                'id' => $fqcn,
                'fqcn' => $fqcn,
                'kind' => 'class',
                'file' => $location['file'],
                'line' => $location['line'],
                'dispatched_from' => [],
                'handled_by' => [],
            ];
        }

        return $entries;
    }
}
