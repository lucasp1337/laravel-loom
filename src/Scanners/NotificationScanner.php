<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\NotificationClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers notification classes via a filesystem walk of app/Notifications/
 * seeded by dispatch sites whose target resolves via PSR-4 to a class
 * anywhere under app/.
 *
 * See docs/scanners/notifications.md for the full design.
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
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array
    {
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker);

        $fsClasses = $this->discoverFromFilesystem($appRoot, $resolver);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot);

        $merged = $fsClasses;

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
     * @return array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>, channels: list<string>, channels_dynamic: bool}>
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
                $relative = $this->relativePath($appRoot, $file->getPathname());
                $results[$class['fqcn']] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'queued' => $resolver->implementsInterface($class['fqcn'], LaravelClasses::SHOULD_QUEUE->value),
                    'queue_config' => $class['queue_config'],
                    'channels' => $class['channels'],
                    'channels_dynamic' => $class['channels_dynamic'],
                ];
            }
        }

        return $results;
    }

    /**
     * Collect dispatch targets whose provisional kind is `notification`.
     * Walks the whole `app/` tree with DispatchSiteVisitor — mirrors
     * JobsScanner / MailableScanner self-contained discovery.
     *
     * @return array<string, 'notification'>
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
                if ($site['provisionalKind'] !== 'notification') {
                    continue;
                }

                $candidates[$site['target']] = 'notification';
            }
        }

        return $candidates;
    }

    /**
     * @return array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>, channels: list<string>, channels_dynamic: bool}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new NotificationClassVisitor;
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
            'channels' => $class['channels'],
            'channels_dynamic' => $class['channels_dynamic'],
        ];
    }

    /**
     * @param  array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>, channels: list<string>, channels_dynamic: bool}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            // Preserve source order from the via() literal — that's the
            // order Laravel uses at runtime when dispatching to channels,
            // and it preserves user intent for ordered channel processing.
            $channels = $location['channels'];

            $entries[] = [
                'fqcn' => $fqcn,
                'file' => $location['file'],
                'line' => $location['line'],
                'queued' => $location['queued'],
                'queue_config' => $location['queued'] ? $location['queue_config'] : null,
                'channels' => $channels,
                'channels_dynamic' => $location['channels_dynamic'],
                'notified_from' => [],
            ];
        }

        return $entries;
    }
}
