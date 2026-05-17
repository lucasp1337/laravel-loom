<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use FilesystemIterator;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Support\AstWalker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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

        usort($unresolved, function (array $a, array $b): int {
            return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
        });

        usort($sites, function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        });

        return [
            'unresolved_dispatches' => $unresolved,
            '_dispatch_sites' => $sites,
        ];
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function iteratePhpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if (! $entry->isFile()) {
                continue;
            }
            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            yield $entry;
        }
    }

    private function relativePath(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
    }
}
