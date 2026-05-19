<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\MailableLocation;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\MailableClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers mailable classes under app/Mail/ plus dispatch-site targets
 * that resolve via PSR-4.
 */
final class MailableScanner implements Scanner
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

        return ['mailables' => $this->emit($merged)];
    }

    /**
     * @return array<string, MailableLocation>
     */
    private function discoverFromFilesystem(string $appRoot, ClassHierarchyResolver $resolver): array
    {
        $mailDir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Mail';
        if (! is_dir($mailDir)) {
            return [];
        }

        $visitor = new MailableClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($mailDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $results[$class->fqcn] = new MailableLocation(
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
     * @return array<string, 'mailable'>
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
                if ($site['provisionalKind'] !== 'mailable') {
                    continue;
                }

                $candidates[$site['target']] = 'mailable';
            }
        }

        return $candidates;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?MailableLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new MailableClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new MailableLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $class->queueConfig,
            );
        }

        return null;
    }

    /**
     * @param  array<string, MailableLocation>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = [
                'fqcn' => $fqcn,
                'file' => $location->file,
                'line' => $location->line,
                'queued' => $location->queued,
                'queue_config' => $location->queued ? $location->queueConfig : null,
                'sent_from' => [],
            ];
        }

        return $entries;
    }
}
