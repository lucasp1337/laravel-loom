<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers job classes under app/Jobs/ plus dispatch-site targets that
 * resolve via PSR-4 to a class under app/.
 */
final class JobsScanner implements Scanner
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
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker);

        $fsClasses = $this->discoverFromFilesystem($appRoot, $resolver);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot);

        $merged = $fsClasses;

        foreach ($dispatchTargets as $fqcn => $kind) {
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn, $resolver);
            if ($located === null) {
                continue;
            }

            // Dispatchable-form sites are ambiguous with events. Accept only
            // when the file is under app/Jobs/ or the class implements
            // ShouldQueue (mirrors EventScanner's symmetric guard).
            if ($kind === 'ambiguous'
                && ! $this->isUnderAppJobs($located['file'])
                && ! $located['queued']
            ) {
                continue;
            }

            $merged[$fqcn] = $located;
        }

        return ['jobs' => $this->emit($merged)];
    }

    private function isUnderAppJobs(string $relativeFile): bool
    {
        return str_starts_with($relativeFile, 'app/Jobs/');
    }

    /**
     * @return array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}>
     */
    private function discoverFromFilesystem(string $appRoot, ClassHierarchyResolver $resolver): array
    {
        $jobsDir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Jobs';
        if (! is_dir($jobsDir)) {
            return [];
        }

        $visitor = new JobClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($jobsDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $relative = $this->relativePath($appRoot, $file->getPathname());
                $results[$class['fqcn']] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'queued' => $resolver->implementsInterface($class['fqcn'], LaravelClasses::SHOULD_QUEUE->value),
                    'queue_config' => $class['queue_config'],
                ];
            }
        }

        return $results;
    }

    /**
     * Kind is preserved so the caller can guard ambiguous targets.
     *
     * @return array<string, 'job'|'ambiguous'>
     */
    private function discoverFromDispatchSites(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new DispatchSiteVisitor;
        $candidates = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getSites() as $site) {
                $kind = $site['provisionalKind'];
                if ($kind !== 'job' && $kind !== 'ambiguous') {
                    continue;
                }

                $target = $site['target'];
                // 'job' wins over 'ambiguous' — once proven unambiguously, lock in.
                if ($kind === 'job' || ! isset($candidates[$target])) {
                    $candidates[$target] = $kind;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new JobClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        $class = AstHelpers::findClass($visitor->getClasses(), $fqcn);
        if ($class === null) {
            return null;
        }

        return [
            'file' => $this->relativePath($appRoot, $absolute),
            'line' => $class['line'],
            'queued' => $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
            'queue_config' => $class['queue_config'],
        ];
    }

    /**
     * @param  array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = [
                'fqcn' => $fqcn,
                'file' => $location['file'],
                'line' => $location['line'],
                'queued' => $location['queued'],
                'queue_config' => $location['queued'] ? $location['queue_config'] : null,
                'dispatched_from' => [],
                'dispatches' => [],
            ];
        }

        return $entries;
    }
}
