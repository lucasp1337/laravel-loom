<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Dto\JobClassRecord;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\JobLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\ClassRecordVisitor;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * Jobs: every class under Jobs/, plus job-kind dispatch targets. Ambiguous
 * (Dispatchable-form) targets are kept only under Jobs/ or when they
 * implement ShouldQueue (or a sub-interface of it). The queue config is emitted for queued jobs only.
 *
 * @implements ClassSpec<JobClassRecord, JobLocation, JobEntry>
 *
 * @internal
 */
final class JobClassSpec implements ClassSpec
{
    public function directory(): PrimitiveDirectory
    {
        return PrimitiveDirectory::JOBS;
    }

    public function classVisitor(): ClassRecordVisitor
    {
        return new JobClassVisitor;
    }

    public function fqcnOf(object $record): string
    {
        return $record->fqcn;
    }

    public function locationOf(object $record, string $relativeFile, ClassHierarchyResolver $resolver): JobLocation
    {
        return new JobLocation(
            file: $relativeFile,
            line: $record->line,
            queued: $resolver->isQueued($record->fqcn),
            queueConfig: $resolver->queueConfig($record->fqcn),
        );
    }

    public function seedVisitors(): array
    {
        return [new DispatchSiteVisitor];
    }

    public function seedsFrom(array $visitors): array
    {
        $seeds = [];

        foreach ($visitors as $visitor) {
            if (! $visitor instanceof DispatchSiteVisitor) {
                continue;
            }

            foreach ($visitor->getSites() as $site) {
                match ($site->provisionalKind) {
                    // Proven job dispatch.
                    DispatchKinds::JOB => $seeds[] = new DispatchSeed($site->target, false, $site->line),
                    // Dispatchable form: could still be an event.
                    DispatchKinds::AMBIGUOUS => $seeds[] = new DispatchSeed($site->target, true, $site->line),
                    default => null,
                };
            }
        }

        return $seeds;
    }

    public function admitsAmbiguous(object $location, bool $underDirectory): bool
    {
        return $underDirectory || $location->queued;
    }

    public function belongsByConvention(object $record, ClassHierarchyResolver $resolver): bool
    {
        return true;
    }

    public function reportsUnlocatableAmbiguous(): bool
    {
        // Cross-linking resolves an ambiguous target that is no event to a job.
        return true;
    }

    public function entryOf(string $fqcn, object $location): JobEntry
    {
        return new JobEntry(
            fqcn: $fqcn,
            file: $location->file,
            line: $location->line,
            queued: $location->queued,
            queueConfig: $location->queued ? $location->queueConfig : null,
        );
    }
}
