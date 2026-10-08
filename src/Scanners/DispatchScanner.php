<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Collects dispatch sites under app/ and routes/ (route closures) and emits `unresolved_dispatches`
 * plus the internal `_dispatch_sites` section.
 *
 * @internal
 */
final class DispatchScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
    }

    /**
     * @return array{unresolved_dispatches: list<UnresolvedDispatchEntry>, _dispatch_sites: list<DispatchSiteRecord>}
     */
    public function scan(string $appRoot): array
    {
        $dirs = array_filter(
            [$appRoot.DIRECTORY_SEPARATOR.'app', $appRoot.DIRECTORY_SEPARATOR.'routes'],
            is_dir(...),
        );
        if ($dirs === []) {
            return ['unresolved_dispatches' => [], '_dispatch_sites' => []];
        }

        /** @var list<DispatchSiteRecord> $sites */
        $sites = [];
        /** @var list<UnresolvedDispatchEntry> $unresolved */
        $unresolved = [];

        $files = [];
        foreach ($dirs as $dir) {
            foreach ($this->iteratePhpFiles($dir) as $file) {
                $files[] = $file;
            }
        }

        foreach ($files as $file) {
            $visitor = new DispatchSiteVisitor;
            $this->walker->walk($file->getPathname(), [$visitor]);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($visitor->getSites() as $site) {
                $site->file = $relative;
                $sites[] = $site;
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
}
