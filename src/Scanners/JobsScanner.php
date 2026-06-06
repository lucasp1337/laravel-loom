<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\JobClassRecord;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\JobLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\TwoPathDiscovery;

/**
 * Discovers job classes under app/Jobs/ plus dispatch-site targets that
 * resolve via PSR-4 to a class under app/.
 */
final class JobsScanner implements Scanner
{
    use ScannerFilesystem;
    use TwoPathDiscovery;

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    protected function walker(): AstWalker
    {
        return $this->walker;
    }

    protected function psr4Locator(): Psr4ClassLocator
    {
        return $this->locator;
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
            if ($kind === DispatchKinds::AMBIGUOUS
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
        return $this->collectFromDirectory(
            $appRoot,
            $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Jobs',
            fn (): JobClassVisitor => new JobClassVisitor,
            fn (JobClassVisitor $visitor): array => $visitor->getClasses(),
            fn (JobClassRecord $record): string => $record->fqcn,
            fn (JobClassRecord $record, string $file): JobLocation => new JobLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($record->fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
            ),
        );
    }

    /**
     * Kind is preserved so the caller can guard ambiguous targets.
     *
     * @return array<string, DispatchKinds>
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
                $kind = $site->provisionalKind;
                if ($kind !== DispatchKinds::JOB && $kind !== DispatchKinds::AMBIGUOUS) {
                    continue;
                }

                $target = $site->target;
                // 'job' wins over 'ambiguous' — once proven unambiguously, lock in.
                if ($kind === DispatchKinds::JOB || ! isset($candidates[$target])) {
                    $candidates[$target] = $kind;
                }
            }
        }

        return $candidates;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?JobLocation
    {
        return $this->locateClassByPsr4(
            $appRoot,
            $fqcn,
            fn (): JobClassVisitor => new JobClassVisitor,
            fn (JobClassVisitor $visitor): array => $visitor->getClasses(),
            fn (JobClassRecord $record): string => $record->fqcn,
            fn (JobClassRecord $record, string $file): JobLocation => new JobLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
            ),
        );
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
