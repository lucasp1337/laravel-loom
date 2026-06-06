<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\NotificationClassRecord;
use Lucasp\Loom\Dto\NotificationEntry;
use Lucasp\Loom\Dto\NotificationLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\NotificationClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\TwoPathDiscovery;

/**
 * Discovers notification classes under app/Notifications/ plus
 * dispatch-site targets that resolve via PSR-4.
 */
final class NotificationScanner implements Scanner
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
     * @return array{notifications: list<NotificationEntry>}
     */
    public function scan(string $appRoot): array
    {
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker);

        $merged = $this->discoverFromFilesystem($appRoot, $resolver);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot);

        foreach (array_keys($dispatchTargets) as $fqcn) {
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn, $resolver);
            if ($located === null) {
                continue;
            }

            $merged[$fqcn] = $located;
        }

        return ['notifications' => $this->emit($merged)];
    }

    /**
     * @return array<string, NotificationLocation>
     */
    private function discoverFromFilesystem(string $appRoot, ClassHierarchyResolver $resolver): array
    {
        return $this->collectFromDirectory(
            $appRoot,
            $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Notifications',
            fn (): NotificationClassVisitor => new NotificationClassVisitor,
            fn (NotificationClassVisitor $visitor): array => $visitor->getClasses(),
            fn (NotificationClassRecord $record): string => $record->fqcn,
            fn (NotificationClassRecord $record, string $file): NotificationLocation => new NotificationLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($record->fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
                channels: $record->channels,
                channelsDynamic: $record->channelsDynamic,
            ),
        );
    }

    /**
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
                if ($site->provisionalKind !== DispatchKinds::NOTIFICATION) {
                    continue;
                }

                $candidates[$site->target] = DispatchKinds::NOTIFICATION;
            }
        }

        return $candidates;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?NotificationLocation
    {
        return $this->locateClassByPsr4(
            $appRoot,
            $fqcn,
            fn (): NotificationClassVisitor => new NotificationClassVisitor,
            fn (NotificationClassVisitor $visitor): array => $visitor->getClasses(),
            fn (NotificationClassRecord $record): string => $record->fqcn,
            fn (NotificationClassRecord $record, string $file): NotificationLocation => new NotificationLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
                channels: $record->channels,
                channelsDynamic: $record->channelsDynamic,
            ),
        );
    }

    /**
     * @param  array<string, NotificationLocation>  $merged
     * @return list<NotificationEntry>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = new NotificationEntry(
                fqcn: $fqcn,
                file: $location->file,
                line: $location->line,
                queued: $location->queued,
                queueConfig: $location->queued ? $location->queueConfig : null,
                channels: $location->channels,
                channelsDynamic: $location->channelsDynamic,
            );
        }

        return $entries;
    }
}
