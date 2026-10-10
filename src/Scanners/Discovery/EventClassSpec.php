<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Dto\ClassRecord;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Dto\SourceLocation;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Scanners\Visitors\ClassRecordVisitor;
use Lucasp\Loom\Scanners\Visitors\DispatchesEventsVisitor;
use Lucasp\Loom\Scanners\Visitors\EventClassVisitor;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * Events: every class under Events/, plus targets of event dispatch forms and
 * `$dispatchesEvents` maps. The Dispatchable form is ambiguous with jobs, so
 * it must resolve to a class under Events/.
 *
 * @implements ClassSpec<ClassRecord, SourceLocation, EventEntry>
 *
 * @internal
 */
final class EventClassSpec implements ClassSpec
{
    public function directory(): PrimitiveDirectory
    {
        return PrimitiveDirectory::EVENTS;
    }

    public function classVisitor(): ClassRecordVisitor
    {
        return new EventClassVisitor;
    }

    public function fqcnOf(object $record): string
    {
        return $record->fqcn;
    }

    public function locationOf(object $record, string $relativeFile, ClassHierarchyResolver $resolver): SourceLocation
    {
        return new SourceLocation(file: $relativeFile, line: $record->line);
    }

    public function seedVisitors(): array
    {
        return [new EventDispatchSiteVisitor, new DispatchesEventsVisitor];
    }

    public function seedsFrom(array $visitors): array
    {
        $seeds = [];

        foreach ($visitors as $visitor) {
            match (true) {
                // Direct dispatch forms: only the Dispatchable form is ambiguous.
                $visitor instanceof EventDispatchSiteVisitor => $this->addTargets($seeds, $visitor),
                // `$dispatchesEvents` entries always name an event.
                $visitor instanceof DispatchesEventsVisitor => $this->addMappings($seeds, $visitor),
                default => null,
            };
        }

        return $seeds;
    }

    public function admitsAmbiguous(object $location, bool $underDirectory): bool
    {
        // A Dispatchable class outside Events/ is far more likely a job.
        return $underDirectory;
    }

    public function entryOf(string $fqcn, object $location): EventEntry
    {
        return new EventEntry(id: $fqcn, fqcn: $fqcn, file: $location->file, line: $location->line);
    }

    /** @param  list<DispatchSeed>  $seeds */
    private function addTargets(array &$seeds, EventDispatchSiteVisitor $visitor): void
    {
        foreach ($visitor->getTargets() as $target) {
            $seeds[] = new DispatchSeed($target->fqcn, $target->form === DispatchForm::DISPATCHABLE);
        }
    }

    /** @param  list<DispatchSeed>  $seeds */
    private function addMappings(array &$seeds, DispatchesEventsVisitor $visitor): void
    {
        foreach ($visitor->getMappings() as $mapping) {
            $seeds[] = new DispatchSeed($mapping->eventFqcn, false);
        }
    }
}
