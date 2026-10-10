<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\DispatchesEventsMapping;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\Visitors\DispatchesEventsVisitor;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Collects dispatch sites under the scan paths and routes/ (route closures) and emits `unresolved_dispatches`
 * plus the internal `_dispatch_sites` section.
 *
 * @internal
 */
final class DispatchScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null, ?ScanScope $scope = null, ?RouteFileDiscovery $routeDiscovery = null)
    {
        $this->routeDiscovery = $routeDiscovery;
        $this->walker = $walker ?? new AstWalker;
        $this->scope = $scope;
    }

    /**
     * @return array{unresolved_dispatches: list<UnresolvedDispatchEntry>, _dispatch_sites: list<DispatchSiteRecord>}
     */
    public function scan(string $appRoot): array
    {
        /** @var list<DispatchSiteRecord> $sites */
        $sites = [];
        /** @var list<UnresolvedDispatchEntry> $unresolved */
        $unresolved = [];

        $seen = [];
        foreach ([...$this->scanFiles($appRoot), ...$this->routeFiles($appRoot)] as $file) {
            if (isset($seen[$file->getPathname()])) {
                continue;
            }
            $seen[$file->getPathname()] = true;

            $visitor = new DispatchSiteVisitor;
            $mappingVisitor = new DispatchesEventsVisitor;
            $this->walker->walk($file->getPathname(), [$visitor, $mappingVisitor]);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($visitor->getSites() as $site) {
                $site->file = $relative;
                $sites[] = $site;
            }

            foreach ($mappingVisitor->getMappings() as $mapping) {
                $sites[] = $this->siteFromMapping($mapping, $relative);
            }

            foreach ($visitor->getUnresolved() as $entry) {
                $unresolved[] = new UnresolvedDispatchEntry(
                    file: $relative,
                    line: $entry->line,
                    expression: $entry->expression,
                    reason: $entry->reason,
                );
            }
        }

        usort($unresolved, fn (UnresolvedDispatchEntry $a, UnresolvedDispatchEntry $b): int => [$a->file, $a->line] <=> [$b->file, $b->line]);
        usort($sites, fn (DispatchSiteRecord $a, DispatchSiteRecord $b): int => [$a->file, $a->line, $a->target] <=> [$b->file, $b->line, $b->target]);

        return [
            'unresolved_dispatches' => $unresolved,
            '_dispatch_sites' => $sites,
        ];
    }

    /** A `$dispatchesEvents` entry is an event dispatched by the model on that hook. */
    private function siteFromMapping(DispatchesEventsMapping $mapping, string $file): DispatchSiteRecord
    {
        return new DispatchSiteRecord(
            classFqcn: $mapping->modelFqcn,
            method: '$dispatchesEvents['.$mapping->hook.']',
            target: $mapping->eventFqcn,
            form: DispatchForm::DISPATCHES_EVENTS,
            provisionalKind: DispatchKinds::EVENT,
            file: $file,
            line: $mapping->line,
        );
    }
}
