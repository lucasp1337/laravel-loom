<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use FilesystemIterator;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\EventClassVisitor;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Discovers event classes via a filesystem walk of app/Events/ seeded by
 * statically resolvable dispatch sites across app/. See
 * docs/scanners/EventScanner.md for the full design.
 */
final class EventScanner implements Scanner
{
    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
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

            // Per §3b/§7a: helper (event(...)) and facade (Event::dispatch(...))
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
     * Convert an FQCN to a guessed app/Events PSR-4 path. Returns null if
     * the file does not exist or cannot be parsed for the class.
     *
     * @return array{file: string, line: int}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?array
    {
        $trimmed = ltrim($fqcn, '\\');
        if ($trimmed === '') {
            return null;
        }
        $segments = explode('\\', $trimmed);

        // Map the leading "App" segment (Laravel default root namespace) to
        // the "app/" directory; leave any other root namespace lowercased.
        $segments[0] = $segments[0] === 'App' ? 'app' : strtolower($segments[0]);

        $relative = implode('/', $segments).'.php';
        $absolute = $appRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_file($absolute)) {
            return null;
        }

        $visitor = new EventClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class['fqcn'] === $fqcn) {
                return [
                    'file' => $this->relativePath($appRoot, $absolute),
                    'line' => $class['line'],
                ];
            }
        }

        return null;
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

    /**
     * @return iterable<SplFileInfo>
     */
    private function iteratePhpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            yield $entry;
        }
    }

    private function relativePath(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
    }
}
