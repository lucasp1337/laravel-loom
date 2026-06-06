<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\MailableClassRecord;
use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Dto\MailableLocation;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\MailableClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\TwoPathDiscovery;

/**
 * Discovers mailable classes under app/Mail/ plus dispatch-site targets
 * that resolve via PSR-4.
 */
final class MailableScanner implements Scanner
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
     * @return array{mailables: list<MailableEntry>}
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
        return $this->collectFromDirectory(
            $appRoot,
            $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Mail',
            fn (): MailableClassVisitor => new MailableClassVisitor,
            fn (MailableClassVisitor $visitor): array => $visitor->getClasses(),
            fn (MailableClassRecord $record): string => $record->fqcn,
            fn (MailableClassRecord $record, string $file): MailableLocation => new MailableLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($record->fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
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
                if ($site->provisionalKind !== DispatchKinds::MAILABLE) {
                    continue;
                }

                $candidates[$site->target] = DispatchKinds::MAILABLE;
            }
        }

        return $candidates;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?MailableLocation
    {
        return $this->locateClassByPsr4(
            $appRoot,
            $fqcn,
            fn (): MailableClassVisitor => new MailableClassVisitor,
            fn (MailableClassVisitor $visitor): array => $visitor->getClasses(),
            fn (MailableClassRecord $record): string => $record->fqcn,
            fn (MailableClassRecord $record, string $file): MailableLocation => new MailableLocation(
                file: $file,
                line: $record->line,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                queueConfig: $record->queueConfig,
            ),
        );
    }

    /**
     * @param  array<string, MailableLocation>  $merged
     * @return list<MailableEntry>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = new MailableEntry(
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
