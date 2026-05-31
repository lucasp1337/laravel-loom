<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
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

/**
 * Discovers notification classes under app/Notifications/ plus
 * dispatch-site targets that resolve via PSR-4.
 */
final class NotificationScanner implements Scanner
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
        $dir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Notifications';
        if (! is_dir($dir)) {
            return [];
        }

        $visitor = new NotificationClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($dir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $results[$class->fqcn] = new NotificationLocation(
                    file: $this->relativePath($appRoot, $file->getPathname()),
                    line: $class->line,
                    queued: $resolver->implementsInterface($class->fqcn, LaravelClasses::SHOULD_QUEUE->value),
                    queueConfig: $class->queueConfig,
                    channels: $class->channels,
                    channelsDynamic: $class->channelsDynamic,
                );
            }
        }

        return $results;
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
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new NotificationClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new NotificationLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $class->queueConfig,
                channels: $class->channels,
                channelsDynamic: $class->channelsDynamic,
            );
        }

        return null;
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
