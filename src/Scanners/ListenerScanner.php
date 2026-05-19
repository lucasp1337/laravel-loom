<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use Lucasp\Loom\Scanners\Visitors\EventSubscribeCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\Sorting;

/**
 * Discovers event listeners from auto-discovery (app/Listeners/),
 * $listen arrays, Event::listen() calls, and $subscribe / Event::subscribe.
 */
final class ListenerScanner implements Scanner
{
    use ScannerFilesystem;

    private const REGISTRATION_SUBSCRIBER = 'subscriber';

    private const REGISTRATION_LISTEN_ARRAY = 'listen_array';

    private const REGISTRATION_EVENT_LISTEN_CALL = 'event_listen_call';

    private const REGISTRATION_AUTO_DISCOVERED = 'auto_discovered';

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

        $autoDiscovered = $this->discoverFromAutoDiscovery($appRoot);
        [$listenArrayPairs, $listenArrayClosures] = $this->discoverFromListenArray($appRoot);
        [$eventListenPairs, $eventListenClosures] = $this->discoverFromEventListenCalls($appRoot);
        $subscriberFqcns = $this->discoverSubscriberFqcns($appRoot);
        [$subscribers, $subscriberClosures, $subscriberForeignPairs] = $this->resolveSubscribers($appRoot, $subscriberFqcns);

        $merged = $this->merge($appRoot, $autoDiscovered, $listenArrayPairs, $eventListenPairs, $subscribers, $subscriberForeignPairs);

        $closureListeners = $this->buildClosureListeners(
            $listenArrayClosures,
            $eventListenClosures,
            $subscriberClosures,
        );

        return [
            'listeners' => $this->emit($merged, $resolver),
            'closure_listeners' => $closureListeners,
        ];
    }

    /**
     * @return array<string, array{file: string, line: int, queued: bool, has_handle: bool, handles: array<int, array{event: string, method: string}>}>
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
                // Auto-discovery requires a literal handle() method.
                if (! $class['has_handle']) {
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
     * Walks the whole app/ tree — custom providers can live outside
     * app/Providers/; the visitor filters by class shape.
     *
     * @return array{0: array<int, array{event: string, listener: string, method: string}>, 1: array<int, array{event: string, file: string, line: int, registration: string}>}
     */
    private function discoverFromListenArray(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [[], []];
        }

        $visitor = new ListenArrayVisitor;
        $pairs = [];
        $closures = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
            foreach ($visitor->getClosurePairs() as $closure) {
                $closures[] = [
                    'event' => $closure['event'],
                    'file' => $relative,
                    'line' => $closure['line'],
                    'registration' => $closure['registration'],
                ];
            }
        }

        return [$pairs, $closures];
    }

    /**
     * @return array{0: array<int, array{event: string, listener: string, method: string}>, 1: array<int, array{event: string, file: string, line: int, registration: string}>}
     */
    private function discoverFromEventListenCalls(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [[], []];
        }

        $visitor = new EventListenCallVisitor;
        $pairs = [];
        $closures = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
            foreach ($visitor->getClosurePairs() as $closure) {
                $closures[] = [
                    'event' => $closure['event'],
                    'file' => $relative,
                    'line' => $closure['line'],
                    'registration' => $closure['registration'],
                ];
            }
        }

        return [$pairs, $closures];
    }

    /**
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
     * Returns (subscriber self-handles, closure registrations, foreign pairs).
     * Dropped if PSR-4 cannot locate the source file.
     *
     * @param  array<int, string>  $fqcns
     * @return array{0: array<string, array{file: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>}>, 1: array<int, array{event: string, file: string, line: int, registration: string}>, 2: array<int, array{event: string, listener: string, method: string}>}
     */
    private function resolveSubscribers(string $appRoot, array $fqcns): array
    {
        $result = [];
        $closures = [];
        $foreignPairs = [];

        foreach ($fqcns as $fqcn) {
            $absolute = $this->locator->locate($appRoot, $fqcn);
            if ($absolute === null) {
                continue;
            }

            $visitor = new SubscriberClassVisitor;
            $this->walker->walk($absolute, [$visitor]);

            $relative = $this->relativePath($appRoot, $absolute);

            foreach ($visitor->getClasses() as $class) {
                if ($class['fqcn'] !== $fqcn) {
                    continue;
                }
                $result[$fqcn] = [
                    'file' => $relative,
                    'line' => $class['line'],
                    'queued' => $class['queued'],
                    'handles' => $class['handles'],
                ];
                foreach ($class['closureHandles'] as $handle) {
                    $closures[] = [
                        'event' => $handle['event'],
                        'file' => $relative,
                        'line' => $handle['line'],
                        'registration' => 'subscriber',
                    ];
                }
                foreach ($class['foreignPairs'] as $pair) {
                    $foreignPairs[] = $pair;
                }
                break;
            }
        }

        return [$result, $closures, $foreignPairs];
    }

    /**
     * Precedence: subscriber > listen_array > event_listen_call > auto_discovered.
     * `handles` is the union across sources.
     *
     * @param  array<string, array{file: string, line: int, queued: bool, has_handle: bool, handles: array<int, array{event: string, method: string}>}>  $autoDiscovered
     * @param  array<int, array{event: string, listener: string, method: string}>  $listenArrayPairs
     * @param  array<int, array{event: string, listener: string, method: string}>  $eventListenPairs
     * @param  array<string, array{file: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>}>  $subscribers
     * @param  array<int, array{event: string, listener: string, method: string}>  $subscriberForeignPairs
     * @return array<string, array{file: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>, registration: string}>
     */
    private function merge(string $appRoot, array $autoDiscovered, array $listenArrayPairs, array $eventListenPairs, array $subscribers, array $subscriberForeignPairs = []): array
    {
        /** @var array<string, array{file: ?string, line: ?int, queued: bool, handles: array<string, array{event: string, method: string}>, registration: ?string}> $acc */
        $acc = [];

        foreach ($autoDiscovered as $fqcn => $data) {
            $handlesSet = [];
            foreach ($data['handles'] as $pair) {
                $key = $pair['event'].'::'.$pair['method'];
                $handlesSet[$key] = $pair;
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
                // Subscriber precedence wins — point at the subscriber class.
                $acc[$fqcn]['file'] = $data['file'];
                $acc[$fqcn]['line'] = $data['line'];
                $acc[$fqcn]['queued'] = $data['queued'];
                if ($this->precedence(self::REGISTRATION_SUBSCRIBER) > $this->precedence($acc[$fqcn]['registration'])) {
                    $acc[$fqcn]['registration'] = self::REGISTRATION_SUBSCRIBER;
                }
            }

            foreach ($data['handles'] as $pair) {
                $key = $pair['event'].'::'.$pair['method'];
                $acc[$fqcn]['handles'][$key] = $pair;
            }
        }

        // Listeners wired imperatively from inside subscribe() bodies.
        foreach ($subscriberForeignPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_SUBSCRIBER);
        }

        $result = [];
        foreach ($acc as $fqcn => $entry) {
            // $listen/Event::listen-only entries lack a file hit; PSR-4 guess
            // or drop (documented v0.1 gap).
            if ($entry['file'] === null || $entry['line'] === null) {
                $located = $this->locateByPsr4Guess($appRoot, $fqcn);
                if ($located === null) {
                    continue;
                }
                $entry['file'] = $located['file'];
                $entry['line'] = $located['line'];
                $entry['queued'] = $located['queued'];
            }

            $handles = array_values($entry['handles']);
            usort($handles, Sorting::byKeys(['event', 'method']));

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
     * @param  array<string, array{file: ?string, line: ?int, queued: bool, handles: array<string, array{event: string, method: string}>, registration: ?string}>  $acc
     * @param  array{event: string, listener: string, method: string}  $pair
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

        $key = $pair['event'].'::'.$pair['method'];
        $acc[$fqcn]['handles'][$key] = ['event' => $pair['event'], 'method' => $pair['method']];

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
     * @return array{file: string, line: int, queued: bool}|null
     */
    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?array
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new ListenerClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        $class = AstHelpers::findClass($visitor->getClasses(), $fqcn);
        if ($class === null) {
            return null;
        }

        return [
            'file' => $this->relativePath($appRoot, $absolute),
            'line' => $class['line'],
            'queued' => $class['queued'],
        ];
    }

    /**
     * @param  array<string, array{file: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>, registration: string}>  $merged
     * @return array<int, array<string, mixed>>
     */
    private function emit(array $merged, ClassHierarchyResolver $resolver): array
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
                'queued' => $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
                'dispatches' => [],
            ];
        }

        return $entries;
    }

    /**
     * Dedup by (event, file, line, registration); sort by (event, file, line).
     *
     * @param  array<int, array{event: string, file: string, line: int, registration: string}>  $listenArrayClosures
     * @param  array<int, array{event: string, file: string, line: int, registration: string}>  $eventListenClosures
     * @param  array<int, array{event: string, file: string, line: int, registration: string}>  $subscriberClosures
     * @return array<int, array<string, mixed>>
     */
    private function buildClosureListeners(
        array $listenArrayClosures,
        array $eventListenClosures,
        array $subscriberClosures,
    ): array {
        /** @var array<string, array{event: string, file: string, line: int, registration: string}> $seen */
        $seen = [];

        foreach ([$listenArrayClosures, $eventListenClosures, $subscriberClosures] as $bucket) {
            foreach ($bucket as $entry) {
                $key = $entry['event'].'|'.$entry['file'].'|'.$entry['line'].'|'.$entry['registration'];
                $seen[$key] = $entry;
            }
        }

        $entries = array_values($seen);
        usort($entries, Sorting::byKeys(['event', 'file', 'line']));

        $result = [];
        foreach ($entries as $entry) {
            $result[] = [
                'event' => $entry['event'],
                'file' => $entry['file'],
                'line' => $entry['line'],
                'registration' => $entry['registration'],
                'queued' => false,
                'dispatches' => [],
            ];
        }

        return $result;
    }
}
