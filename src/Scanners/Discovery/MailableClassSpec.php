<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Dto\MailableClassRecord;
use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Dto\MailableLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\ClassRecordVisitor;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\MailableClassVisitor;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * Mailables: every concrete class under Mail/, plus mailable-kind dispatch
 * targets (never ambiguous). The queue config is emitted for queued ones only.
 *
 * @implements ClassSpec<MailableClassRecord, MailableLocation, MailableEntry>
 *
 * @internal
 */
final class MailableClassSpec implements ClassSpec
{
    public function directory(): PrimitiveDirectory
    {
        return PrimitiveDirectory::MAIL;
    }

    public function classVisitor(): ClassRecordVisitor
    {
        return new MailableClassVisitor;
    }

    public function fqcnOf(object $record): string
    {
        return $record->fqcn;
    }

    public function locationOf(object $record, string $relativeFile, ClassHierarchyResolver $resolver): MailableLocation
    {
        return new MailableLocation(
            file: $relativeFile,
            line: $record->line,
            queued: $resolver->implementsInterface($record->fqcn, LaravelClasses::SHOULD_QUEUE->value),
            queueConfig: $record->queueConfig,
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
                if ($site->provisionalKind === DispatchKinds::MAILABLE) {
                    $seeds[] = new DispatchSeed($site->target, false);
                }
            }
        }

        return $seeds;
    }

    public function admitsAmbiguous(object $location, bool $underDirectory): bool
    {
        // No seed is ambiguous, so this is never consulted.
        return true;
    }

    public function entryOf(string $fqcn, object $location): MailableEntry
    {
        return new MailableEntry(
            fqcn: $fqcn,
            file: $location->file,
            line: $location->line,
            queued: $location->queued,
            queueConfig: $location->queued ? $location->queueConfig : null,
        );
    }
}
