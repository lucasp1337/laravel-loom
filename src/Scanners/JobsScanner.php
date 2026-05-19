<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\JobLocation;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
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
     * @return array{jobs: list<JobEntry>}
     */
    public function scan(string $appRoot): array
    {
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker);

        $merged = $this->discoverFromFilesystem($appRoot, $resolver);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot);

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
                && ! $this->isUnderAppJobs($located->file)
                && ! $located->queued
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
     * @return array<string, JobLocation>
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
                $results[$class->fqcn] = new JobLocation(
                    file: $this->relativePath($appRoot, $file->getPathname()),
                    line: $class->line,
                    queued: $resolver->implementsInterface($class->fqcn, LaravelClasses::SHOULD_QUEUE->value),
                    queueConfig: $class->queueConfig,
                );
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

    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?JobLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new JobClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new JobLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $class->queueConfig,
            );
        }

        return null;
    }

    /**
     * @param  array<string, JobLocation>  $merged
     * @return list<JobEntry>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = new JobEntry(
                fqcn: $fqcn,
                file: $location->file,
                line: $location->line,
                queued: $location->queued,
                queueConfig: $location->queued ? $location->queueConfig : null,
            );
        }

        return $entries;
    }
}
