<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use FilesystemIterator;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use Lucasp\Loom\Scanners\Visitors\EventSubscribeCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Discovers Laravel event listeners across three sources: auto-discovery via
 * app/Listeners/, the $listen array on EventServiceProvider, and Event::listen()
 * static calls. See docs/scanners/ListenerScanner.md for the full design.
 */
final class ListenerScanner implements Scanner
{
    private const REGISTRATION_SUBSCRIBER = 'subscriber';

    private const REGISTRATION_LISTEN_ARRAY = 'listen_array';

    private const REGISTRATION_EVENT_LISTEN_CALL = 'event_listen_call';

    private const REGISTRATION_AUTO_DISCOVERED = 'auto_discovered';

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
        $autoDiscovered = $this->discoverFromAutoDiscovery($appRoot);
        $listenArrayPairs = $this->discoverFromListenArray($appRoot);
        $eventListenPairs = $this->discoverFromEventListenCalls($appRoot);
        $subscriberFqcns = $this->discoverSubscriberFqcns($appRoot);
        $subscribers = $this->resolveSubscribers($appRoot, $subscriberFqcns);

        $merged = $this->merge($appRoot, $autoDiscovered, $listenArrayPairs, $eventListenPairs, $subscribers);

        return ['listeners' => $this->emit($merged)];
    }

    /**
     * Walk app/Listeners/ to collect listener classes with their handle() shape.
     *
     * @return array<string, array{file: string, line: int, queued: bool, has_handle: bool, handles: array<int, string>}>
     */
    private function discoverFromAutoDiscovery(string $appRoot): array
    {
        $listenersDir = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Listeners';
        if (! is_dir($listenersDir)) {
            return [];
        }

        $visitor = new ListenerClassVisitor;
        $results = [];

        foreach ($this->iteratePhpFiles($listenersDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                if (! $class['has_handle']) {
                    // Auto-discovery requires a literal handle() method.
                    continue;
                }

                $results[$class['fqcn']] = [
                    'file' => $this->relativePath($appRoot, $file->getPathname()),
                    'line' => $class['line'],
                    'queued' => $class['queued'],
                    'has_handle' => $class['has_handle'],
                    'handles' => $class['handles'],
                ];
            }
        }

        return $results;
    }

    /**
     * Walk app/ for $listen arrays on EventServiceProvider classes.
     *
     * Walks the whole app/ tree rather than just app/Providers/ because
     * custom service providers (e.g. App\Domain\Invoicing\Providers\
     * InvoicingServiceProvider) commonly live outside the conventional
     * directory; the ListenArrayVisitor filters by class shape so the
     * wider walk does not introduce false positives.
     *
     * @return array<int, array{event: string, listener: string}>
     */
    private function discoverFromListenArray(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new ListenArrayVisitor;
        $pairs = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Walk app/ for Event::listen() static calls.
     *
     * Custom service providers (e.g. an InvoicingServiceProvider in a DDD
     * layout) register listeners via Event::listen() in their boot()
     * method but commonly live outside app/Providers/. The visitor itself
     * is location-agnostic, so widening the walk is the correct fix.
     *
     * @return array<int, array{event: string, listener: string}>
     */
    private function discoverFromEventListenCalls(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $visitor = new EventListenCallVisitor;
        $pairs = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Walk app/ for `$subscribe` arrays and Event::subscribe(...) calls and
     * return the deduped set of subscriber FQCNs.
     *
     * @return array<int, string>
     */
    private function discoverSubscriberFqcns(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $arrayVisitor = new SubscribeArrayVisitor;
        $callVisitor = new EventSubscribeCallVisitor;

        $seen = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$arrayVisitor, $callVisitor]);
            foreach ($arrayVisitor->getSubscribers() as $fqcn) {
                $seen[$fqcn] = true;
            }
            foreach ($callVisitor->getSubscribers() as $fqcn) {
                $seen[$fqcn] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Resolve each subscriber FQCN to a file + parsed subscribe() return-array.
     *
     * Subscribers whose source file can't be located via PSR-4 guess, or that
     * have no parseable subscribe() return-array, are dropped (documented gap).
     *
     * @param  array<int, string>  $fqcns
     * @return array<string, array{file: string, line: int, queued: bool, handles: array<int, string>}>
     */
    private function resolveSubscribers(string $appRoot, array $fqcns): array
    {
        $result = [];

        foreach ($fqcns as $fqcn) {
            $absolute = $this->absolutePathForFqcn($appRoot, $fqcn);
            if ($absolute === null || ! is_file($absolute)) {
                continue;
            }

            $visitor = new SubscriberClassVisitor;
            $this->walker->walk($absolute, [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                if ($class['fqcn'] !== $fqcn) {
                    continue;
                }
                $result[$fqcn] = [
                    'file' => $this->relativePath($appRoot, $absolute),
                    'line' => $class['line'],
                    'queued' => $class['queued'],
                    'handles' => $class['handles'],
                ];
                break;
            }
        }

        return $result;
    }

    /**
     * Combine the four discovery sources by listener FQCN. Precedence for the
     * `registration` field is `subscriber > listen_array > event_listen_call > auto_discovered`.
     * `handles` is the union of events across all sources.
     *
     * @param  array<string, array{file: string, line: int, queued: bool, has_handle: bool, handles: array<int, string>}>  $autoDiscovered
     * @param  array<int, array{event: string, listener: string}>  $listenArrayPairs
     * @param  array<int, array{event: string, listener: string}>  $eventListenPairs
     * @param  array<string, array{file: string, line: int, queued: bool, handles: array<int, string>}>  $subscribers
     * @return array<string, array{file: string, line: int, queued: bool, handles: array<int, string>, registration: string}>
     */
    private function merge(string $appRoot, array $autoDiscovered, array $listenArrayPairs, array $eventListenPairs, array $subscribers): array
    {
        /** @var array<string, array{file: ?string, line: ?int, queued: bool, handles: array<string, true>, registration: ?string}> $acc */
        $acc = [];

        foreach ($autoDiscovered as $fqcn => $data) {
            $handlesSet = [];
            foreach ($data['handles'] as $event) {
                $handlesSet[$event] = true;
            }
            $acc[$fqcn] = [
                'file' => $data['file'],
                'line' => $data['line'],
                'queued' => $data['queued'],
                'handles' => $handlesSet,
                'registration' => self::REGISTRATION_AUTO_DISCOVERED,
            ];
        }

        foreach ($eventListenPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_EVENT_LISTEN_CALL);
        }

        foreach ($listenArrayPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_LISTEN_ARRAY);
        }

        foreach ($subscribers as $fqcn => $data) {
            if (! isset($acc[$fqcn])) {
                $acc[$fqcn] = [
                    'file' => $data['file'],
                    'line' => $data['line'],
                    'queued' => $data['queued'],
                    'handles' => [],
                    'registration' => self::REGISTRATION_SUBSCRIBER,
                ];
            } else {
                // Subscriber discovery has highest precedence; overwrite file/line/queued
                // so we point at the subscriber class rather than an unrelated location.
                $acc[$fqcn]['file'] = $data['file'];
                $acc[$fqcn]['line'] = $data['line'];
                $acc[$fqcn]['queued'] = $data['queued'];
                if ($this->precedence(self::REGISTRATION_SUBSCRIBER) > $this->precedence($acc[$fqcn]['registration'])) {
                    $acc[$fqcn]['registration'] = self::REGISTRATION_SUBSCRIBER;
                }
            }

            foreach ($data['handles'] as $event) {
                $acc[$fqcn]['handles'][$event] = true;
            }
        }

        $result = [];
        foreach ($acc as $fqcn => $entry) {
            // Listeners discovered only via $listen / Event::listen lack a file
            // hit from path B. Locate via PSR-4 guess; if that fails the schema
            // can't be satisfied — drop the listener (documented v0.1 gap).
            if ($entry['file'] === null || $entry['line'] === null) {
                $located = $this->locateByPsr4Guess($appRoot, $fqcn);
                if ($located === null) {
                    continue;
                }
                $entry['file'] = $located['file'];
                $entry['line'] = $located['line'];
                $entry['queued'] = $located['queued'];
            }

            $handles = array_keys($entry['handles']);
            sort($handles);

            $registration = $entry['registration'] ?? self::REGISTRATION_AUTO_DISCOVERED;

            $result[$fqcn] = [
                'file' => $entry['file'],
                'line' => $entry['line'],
                'queued' => $entry['queued'],
                'handles' => $handles,
                'registration' => $registration,
            ];
        }

        return $result;
    }

    /**
     * Walk-style insert: ensure the listener exists in the accumulator, add the
     * event to its handles set, and upgrade its `registration` if the incoming
     * source has higher precedence than what's currently recorded.
     *
     * @param  array<string, array{file: ?string, line: ?int, queued: bool, handles: array<string, true>, registration: ?string}>  $acc
     * @param  array{event: string, listener: string}  $pair
     */
    private function applyPair(array &$acc, array $pair, string $registration): void
    {
        $fqcn = $pair['listener'];

        if (! isset($acc[$fqcn])) {
            $acc[$fqcn] = [
                'file' => null,
                'line' => null,
                'queued' => false,
                'handles' => [],
                'registration' => $registration,
            ];
        }

        $acc[$fqcn]['handles'][$pair['event']] = true;

        if ($this->precedence($registration) > $this->precedence($acc[$fqcn]['registration'])) {
            $acc[$fqcn]['registration'] = $registration;
        }
    }

    private function precedence(?string $registration): int
    {
        return match ($registration) {
            self::REGISTRATION_SUBSCRIBER => 4,
            self::REGISTRATION_LISTEN_ARRAY => 3,
            self::REGISTRATION_EVENT_LISTEN_CALL => 2,
            self::REGISTRATION_AUTO_DISCOVERED => 1,
            default => 0,
        };
    }

    /**
     * Locate the file for a listener FQCN by mapping the leading App\ segment
     * to app/, then re-running ListenerClassVisitor against the located file
     * so `queued` is accurate for path A/C-only listeners.
     *
     * @return array{file: string, line: int, queued: bool}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?array
    {
        $absolute = $this->absolutePathForFqcn($appRoot, $fqcn);
        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        $visitor = new ListenerClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class['fqcn'] === $fqcn) {
                return [
                    'file' => $this->relativePath($appRoot, $absolute),
                    'line' => $class['line'],
                    'queued' => $class['queued'],
                ];
            }
        }

        return null;
    }

    /**
     * Build schema-shaped listener entries, sorted by FQCN ascending.
     *
     * @param  array<string, array{file: string, line: int, queued: bool, handles: array<int, string>, registration: string}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $data) {
            $entries[] = [
                'fqcn' => $fqcn,
                'file' => $data['file'],
                'line' => $data['line'],
                'handles' => $data['handles'],
                'registration' => $data['registration'],
                'queued' => $data['queued'],
                'dispatches' => [],
            ];
        }

        return $entries;
    }

    /**
     * Map an FQCN to its likely on-disk path under $appRoot using the Laravel
     * default PSR-4 convention (App\ → app/). Returns null only for an empty
     * FQCN; file existence is the caller's concern.
     */
    private function absolutePathForFqcn(string $appRoot, string $fqcn): ?string
    {
        $trimmed = ltrim($fqcn, '\\');
        if ($trimmed === '') {
            return null;
        }
        $segments = explode('\\', $trimmed);
        $segments[0] = $segments[0] === 'App' ? 'app' : strtolower($segments[0]);

        $relative = implode('/', $segments).'.php';

        return $appRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
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
