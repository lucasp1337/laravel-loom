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
 * Which call shapes count is the {@see DispatchRules} table. A site is recorded
 * inside a class method, or inside a closure within one; under routes/ only
 * closure bodies yield sites. Top-level code outside any class is skipped. Each
 * site carries target, kind, form, enclosing class and method, and an
 * `inClosure` flag the cross-link pass resolves (see ClosureOwnershipPhase).
 * `X::dispatch()` is `ambiguous` until cross-link finalizes it as an event when
 * the target is in `events[]`, else a job.
 *
 * A target that cannot be resolved is emitted as an unresolved dispatch with a
 * reason: `dynamic_class_name` (a variable), `string_concatenation` (concat or
 * interpolation), `container_resolution` (`app()`, `resolve()`, `->make()`) or
 * `conditional_dispatch` (a ternary whose branches do not both resolve; when
 * both are concrete `new X` two sites are emitted instead).
 *
 * The dispatched value may sit in a fluent chain, which is read for
 * `overrides` (`locale`, `mailer`, `connection`, `queue`, `delay` as an integer
 * literal, `after_commit`) both on the argument instance and on the returned
 * pending dispatch, plus the `Mail::to()->...->send()` receiver chain.
 * Notifications read only the argument-instance chain. See
 * {@see ChainModifierExtractor}. A literal-true `->afterResponse()` sets the
 * `after_response` mode. `Queue::pushRaw` is not recorded, and `ShouldQueue`
 * and the sync driver are never evaluated against `mode`.
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

        $unresolved = array_values(collect($unresolved)->sort(fn (UnresolvedDispatchEntry $a, UnresolvedDispatchEntry $b): int => [$a->file, $a->line] <=> [$b->file, $b->line])->all());
        $sites = array_values(collect($sites)->sort(fn (DispatchSiteRecord $a, DispatchSiteRecord $b): int => [$a->file, $a->line, $a->target] <=> [$b->file, $b->line, $b->target])->all());

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
