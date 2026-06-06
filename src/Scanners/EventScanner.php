<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ClassRecord;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Dto\SourceLocation;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Scanners\Visitors\EventClassVisitor;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\TwoPathDiscovery;

/**
 * Discovers event classes under app/Events/ plus targets reached from
 * statically resolvable dispatch sites elsewhere in app/.
 */
final class EventScanner implements Scanner
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
     * @return array{events: list<EventEntry>}
     */
    public function scan(string $appRoot): array
    {
        $merged = $this->discoverFromFilesystem($appRoot);
        $dispatchTargets = $this->discoverFromDispatchSites($appRoot, $merged);

        foreach (array_keys($dispatchTargets) as $fqcn) {
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn);
            if ($located === null) {
                continue;
            }

            $merged[$fqcn] = $located;
        }

        return ['events' => $this->emit($merged)];
    }

    /**
     * @return array<string, SourceLocation>
     */
    private function discoverFromFilesystem(string $appRoot): array
    {
        return $this->collectFromDirectory(
            $appRoot,
            $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Events',
            fn (): EventClassVisitor => new EventClassVisitor,
            fn (EventClassVisitor $visitor): array => $visitor->getClasses(),
            fn (ClassRecord $record): string => $record->fqcn,
            fn (ClassRecord $record, string $file): SourceLocation => new SourceLocation(
                file: $file,
                line: $record->line,
            ),
        );
    }

    /**
     * @param  array<string, SourceLocation>  $fsClasses
     * @return array<string, null>
     */
    private function discoverFromDispatchSites(string $appRoot, array $fsClasses): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new EventDispatchSiteVisitor;
        /** @var array<string, bool> $candidates true when seen via an unambiguous form */
        $candidates = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getTargets() as $target) {
                $isUnambiguous = $target->form !== DispatchForm::DISPATCHABLE;
                if (! isset($candidates[$target->fqcn])) {
                    $candidates[$target->fqcn] = $isUnambiguous;
                } elseif ($isUnambiguous) {
                    $candidates[$target->fqcn] = true;
                }
            }
        }

        $kept = [];
        foreach ($candidates as $fqcn => $unambiguous) {
            if (isset($fsClasses[$fqcn])) {
                $kept[$fqcn] = null;

                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn);
            if ($located === null) {
                continue;
            }

            // Dispatchable form is ambiguous with jobs — accept only when the
            // resolved file is under app/Events/.
            if ($unambiguous || str_starts_with($located->file, 'app/Events/')) {
                $kept[$fqcn] = null;
            }
        }

        return $kept;
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?SourceLocation
    {
        return $this->locateClassByPsr4(
            $appRoot,
            $fqcn,
            fn (): EventClassVisitor => new EventClassVisitor,
            fn (EventClassVisitor $visitor): array => $visitor->getClasses(),
            fn (ClassRecord $record): string => $record->fqcn,
            fn (ClassRecord $record, string $file): SourceLocation => new SourceLocation(
                file: $file,
                line: $record->line,
            ),
        );
    }

    /**
     * @param  array<string, SourceLocation>  $merged
     * @return list<EventEntry>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = new EventEntry(
                id: $fqcn,
                fqcn: $fqcn,
                file: $location->file,
                line: $location->line,
            );
        }

        return $entries;
    }
}
