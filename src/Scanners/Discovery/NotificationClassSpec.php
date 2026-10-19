<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Dto\NotificationClassRecord;
use Lucasp\Loom\Dto\NotificationEntry;
use Lucasp\Loom\Dto\NotificationLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\ClassRecordVisitor;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\NotificationClassVisitor;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * Notifications: every concrete class under Notifications/ that is one
 * (extends Notification or a framework notification such as `ResetPassword`,
 * or declares via()), plus notification-kind
 * dispatch targets (never ambiguous). Adds the declared `via()` channels.
 *
 * @implements ClassSpec<NotificationClassRecord, NotificationLocation, NotificationEntry>
 *
 * @internal
 */
final class NotificationClassSpec implements ClassSpec
{
    public function directory(): PrimitiveDirectory
    {
        return PrimitiveDirectory::NOTIFICATIONS;
    }

    public function classVisitor(): ClassRecordVisitor
    {
        return new NotificationClassVisitor;
    }

    public function fqcnOf(object $record): string
    {
        return $record->fqcn;
    }

    public function locationOf(object $record, string $relativeFile, ClassHierarchyResolver $resolver): NotificationLocation
    {
        return new NotificationLocation(
            file: $relativeFile,
            line: $record->line,
            queued: $resolver->isQueued($record->fqcn),
            queueConfig: $resolver->queueConfig($record->fqcn, interfaceWinsOverFalse: true),
            channels: $record->channels,
            channelsDynamic: $record->channelsDynamic,
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
                if ($site->provisionalKind === DispatchKinds::NOTIFICATION) {
                    $seeds[] = new DispatchSeed($site->target, false, $site->line);
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

    public function belongsByConvention(object $record, ClassHierarchyResolver $resolver): bool
    {
        // A class under Notifications/ is a notification only when it extends
        // Notification, a framework notification or an unseen vendor parent, or declares via().
        return collect(LaravelClasses::notificationClasses())->contains(fn (string $base): bool => $resolver->mayExtend($record->fqcn, $base))
            || isset($resolver->effectiveMethods($record->fqcn)['via']);
    }

    public function reportsUnlocatableAmbiguous(): bool
    {
        return false;
    }

    public function entryOf(string $fqcn, object $location): NotificationEntry
    {
        return new NotificationEntry(
            fqcn: $fqcn,
            file: $location->file,
            line: $location->line,
            queued: $location->queued,
            queueConfig: $location->queued ? $location->queueConfig : null,
            channels: $location->channels,
            channelsDynamic: $location->channelsDynamic,
        );
    }
}
