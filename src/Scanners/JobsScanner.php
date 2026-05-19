<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\DispatchSiteVisitor;
use Lucasp\Loom\Scanners\Visitors\JobClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelContracts;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

/**
 * Discovers job classes via a filesystem walk of app/Jobs/ seeded by
 * dispatch sites whose target resolves via PSR-4 to a class anywhere
 * under app/.
 *
 * See docs/scanners/jobs.md for the full design.
 */
final class JobsScanner implements Scanner
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

        foreach ($dispatchTargets as $fqcn => $kind) {
            if (isset($merged[$fqcn])) {
                continue;
            }

            $located = $this->locateByPsr4Guess($appRoot, $fqcn, $resolver);
            if ($located === null) {
                continue;
            }

            // Ambiguous Dispatchable-form sites (`X::dispatch()`) don't prove
            // X is a job — events use the same trait. EventScanner gates the
            // symmetric case to classes under `app/Events/`; mirror that here:
            // for ambiguous targets, only keep the entry if the file is under
            // `app/Jobs/` or the class transitively implements ShouldQueue
            // (via its own `implements` clause or any ancestor indexed under
            // `app/`). The helper form `dispatch(new X)` / `Bus::dispatch(new X)`
            // is unambiguous and bypasses the guard.
            if ($kind === 'ambiguous'
                && ! $this->isUnderAppJobs($located['file'])
                && ! $located['queued']
            ) {
                continue;
            }

            $merged[$fqcn] = $located;
        }

        return ['jobs' => $this->emit($merged)];
    }

    /**
     * The located file's relative path is forward-slashed by `relativePath()`,
     * so a simple prefix check is portable.
     */
    private function isUnderAppJobs(string $relativeFile): bool
    {
        return str_starts_with($relativeFile, 'app/Jobs/');
    }

    /**
     * @return array<string, array{file: string, line: int, queued: bool, queue_config: array<string, string|int|null>}>
     */
    private function discoverFromFilesystem(string $appRoot, ClassHierarchyResolver $resolver): array
    {
        $jobsDir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Jobs';
        if (! is_dir($jobsDir)) {
            return [];
        }

        $visitor = new JobClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($jobsDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $relative = $this->relativePath($appRoot, $file->getPathname());
                $results[$class['fqcn']] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'queued' => $resolver->implementsInterface($class['fqcn'], LaravelContracts::SHOULD_QUEUE),
                    'queue_config' => $class['queue_config'],
                ];
            }
        }

        return $results;
    }

    /**
     * Collect dispatch targets that look like job dispatches: helper form
     * `dispatch(new X)` / `Bus::dispatch(new X)` (provisionalKind=job) and
     * ambiguous Dispatchable form (`X::dispatch()`). The kind is preserved
     * so the caller can apply a stricter guard for ambiguous targets — a
     * Dispatchable static call alone doesn't prove the target is a job.
     *
     * @return array<string, 'job'|'ambiguous'>
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
                $kind = $site['provisionalKind'];
                // Only job-shaped and ambiguous (Dispatchable-form) sites
                // can seed jobs[]. Event, mailable, and notification kinds
                // belong to their own scanners.
                if ($kind !== 'job' && $kind !== 'ambiguous') {
                    continue;
                }

                $target = $site['target'];
                // Helper-form `dispatch(new X)` / `Bus::dispatch(new X)` wins
                // over ambiguous `X::dispatch()` — once one site proves X is a
                // job unambiguously, that's the final answer for X.
                if ($kind === 'job' || ! isset($candidates[$target])) {
                    $candidates[$target] = $kind;
                }
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

        $visitor = new JobClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        $class = AstHelpers::findClass($visitor->getClasses(), $fqcn);
        if ($class === null) {
            return null;
        }

        return [
            'file' => $this->relativePath($appRoot, $absolute),
            'line' => $class['line'],
            'queued' => $resolver->implementsInterface($fqcn, LaravelContracts::SHOULD_QUEUE),
            'queue_config' => $class['queue_config'],
        ];
    }

    /**
     * Build schema-shaped entries, sorted by FQCN.
     *
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
                'dispatched_from' => [],
                'dispatches' => [],
            ];
        }

        return $entries;
    }
}
