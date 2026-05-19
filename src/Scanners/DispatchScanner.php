<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\Sorting;

/**
 * Walks the entire app/ tree collecting every statically recognisable
 * dispatch site (event/job/dispatchable) and emits both the public
 * `unresolved_dispatches` section and the internal `_dispatch_sites`
 * section consumed by IndexBuilder's cross-link pass.
 *
 * See docs/scanners/dispatches.md for the full design.
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
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return ['unresolved_dispatches' => [], '_dispatch_sites' => []];
        }

        /** @var array<int, array<string, mixed>> $sites */
        $sites = [];
        /** @var array<int, array<string, mixed>> $unresolved */
        $unresolved = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $visitor = new DispatchSiteVisitor;
            $this->walker->walk($file->getPathname(), [$visitor]);

            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($visitor->getSites() as $site) {
                $site['file'] = $relative;
                $sites[] = $site;
            }

            foreach ($visitor->getUnresolved() as $entry) {
                $entry['file'] = $relative;
                $unresolved[] = $entry;
            }
        }

        usort($unresolved, Sorting::byKeys(['file', 'line']));
        usort($sites, Sorting::byKeys(['file', 'line', 'target']));

        return [
            'unresolved_dispatches' => $unresolved,
            '_dispatch_sites' => $sites,
        ];
    }
}
