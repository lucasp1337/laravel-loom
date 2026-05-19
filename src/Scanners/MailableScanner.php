<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\MailableClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers mailable classes via a filesystem walk of app/Mail/ seeded by
 * dispatch sites whose target resolves via PSR-4 to a class anywhere
 * under app/.
 *
 * See docs/scanners/mailables.md for the full design.
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

        return ['mailables' => $this->emit($merged)];
    }

    /**
     * @return array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}>
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
                $relative = $this->relativePath($appRoot, $file->getPathname());
                $results[$class['fqcn']] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'queued' => $resolver->implementsInterface($class['fqcn'], LaravelClasses::SHOULD_QUEUE->value),
                    'queue_config' => $class['queue_config'],
                ];
            }
        }

        return $results;
    }

    /**
     * Collect dispatch targets whose provisional kind is `mailable`. Walks
     * the whole `app/` tree with DispatchSiteVisitor — mirrors JobsScanner's
     * self-contained discovery pattern rather than reading from
     * `_dispatch_sites[]` (the cross-link pass owns that data).
     *
     * Ambiguous Dispatchable-form sites don't apply to mailables: mailables
     * are dispatched via `Mail::send/queue/later(...)` and the
     * `Mail::to/cc/bcc/locale/mailer(...)->send/queue/later(...)` chain,
     * never via the `Dispatchable` trait.
     *
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

    /**
     * @return array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn, ClassHierarchyResolver $resolver): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new MailableClassVisitor;
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
        ];
    }

    /**
     * @param  array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $location) {
            $entries[] = [
                'fqcn' => $fqcn,
                'file' => $location['file'],
                'line' => $location['line'],
                'queued' => $location['queued'],
                'queue_config' => $location['queued'] ? $location['queue_config'] : null,
                'sent_from' => [],
            ];
        }

        return $entries;
    }
}
