<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ClosureListenerEntry;
use Lucasp\Loom\Dto\ClosureListenerRecord;
use Lucasp\Loom\Dto\ListenerEntry;
use Lucasp\Loom\Dto\ListenerHandle;
use Lucasp\Loom\Dto\ListenerLocation;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use Lucasp\Loom\Scanners\Visitors\EventSubscribeCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;

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
     * @return array{listeners: list<ListenerEntry>, closure_listeners: list<ClosureListenerEntry>}
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

        return [
            'listeners' => $this->emit($merged, $resolver),
            'closure_listeners' => $this->buildClosureListeners(
                $listenArrayClosures,
                $eventListenClosures,
                $subscriberClosures,
            ),
        ];
    }

    /**
     * @return array<string, ListenerLocation>
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
                if (! $class->hasHandle) {
                    continue;
                }

                $location = new ListenerLocation(
                    file: $this->relativePath($appRoot, $file->getPathname()),
                    line: $class->line,
                    queued: $class->queued,
                    registration: self::REGISTRATION_AUTO_DISCOVERED,
                );
                foreach ($class->handles as $handle) {
                    $location->handles[$handle->event.'::'.$handle->method] = $handle;
                }
                $results[$class->fqcn] = $location;
            }
        }

        return $results;
    }

    /**
     * @return array{0: list<ListenerPair>, 1: list<ClosureListenerRecord>}
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
                $closures[] = new ClosureListenerRecord(
                    event: $closure->event,
                    file: $relative,
                    line: $closure->line,
                    registration: $closure->registration,
                );
            }
        }

        return [$pairs, $closures];
    }

    /**
     * @return array{0: list<ListenerPair>, 1: list<ClosureListenerRecord>}
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
                $closures[] = new ClosureListenerRecord(
                    event: $closure->event,
                    file: $relative,
                    line: $closure->line,
                    registration: $closure->registration,
                );
            }
        }

        return [$pairs, $closures];
    }

    /**
     * @return list<string>
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
     * @param  list<string>  $fqcns
     * @return array{0: array<string, ListenerLocation>, 1: list<ClosureListenerRecord>, 2: list<ListenerPair>}
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
                if ($class->fqcn !== $fqcn) {
                    continue;
                }
                $location = new ListenerLocation(
                    file: $relative,
                    line: $class->line,
                    queued: $class->queued,
                    registration: self::REGISTRATION_SUBSCRIBER,
                );
                foreach ($class->handles as $handle) {
                    $location->handles[$handle->event.'::'.$handle->method] = $handle;
                }
                $result[$fqcn] = $location;

                foreach ($class->closureHandles as $closure) {
                    $closures[] = new ClosureListenerRecord(
                        event: $closure->event,
                        file: $relative,
                        line: $closure->line,
                        registration: 'subscriber',
                    );
                }
                foreach ($class->foreignPairs as $pair) {
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
     * @param  array<string, ListenerLocation>  $autoDiscovered
     * @param  list<ListenerPair>  $listenArrayPairs
     * @param  list<ListenerPair>  $eventListenPairs
     * @param  array<string, ListenerLocation>  $subscribers
     * @param  list<ListenerPair>  $subscriberForeignPairs
     * @return array<string, ListenerLocation>
     */
    private function merge(string $appRoot, array $autoDiscovered, array $listenArrayPairs, array $eventListenPairs, array $subscribers, array $subscriberForeignPairs = []): array
    {
        $acc = $autoDiscovered;

        foreach ($eventListenPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_EVENT_LISTEN_CALL);
        }

        foreach ($listenArrayPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_LISTEN_ARRAY);
        }

        foreach ($subscribers as $fqcn => $sub) {
            if (! isset($acc[$fqcn])) {
                $acc[$fqcn] = $sub;
            } else {
                // Subscriber precedence wins — point at the subscriber class.
                $acc[$fqcn]->file = $sub->file;
                $acc[$fqcn]->line = $sub->line;
                $acc[$fqcn]->queued = $sub->queued;
                if ($this->precedence(self::REGISTRATION_SUBSCRIBER) > $this->precedence($acc[$fqcn]->registration)) {
                    $acc[$fqcn]->registration = self::REGISTRATION_SUBSCRIBER;
                }
                foreach ($sub->handles as $key => $handle) {
                    $acc[$fqcn]->handles[$key] = $handle;
                }
            }
        }

        // Listeners wired imperatively from inside subscribe() bodies.
        foreach ($subscriberForeignPairs as $pair) {
            $this->applyPair($acc, $pair, self::REGISTRATION_SUBSCRIBER);
        }

        $result = [];
        foreach ($acc as $fqcn => $loc) {
            // $listen/Event::listen-only entries lack a file hit; PSR-4 guess or drop.
            if ($loc->file === null || $loc->line === null) {
                $located = $this->locateByPsr4Guess($appRoot, $fqcn);
                if ($located === null) {
                    continue;
                }
                $loc->file = $located->file;
                $loc->line = $located->line;
                $loc->queued = $located->queued;
            }

            $result[$fqcn] = $loc;
        }

        return $result;
    }

    /**
     * @param  array<string, ListenerLocation>  $acc
     */
    private function applyPair(array &$acc, ListenerPair $pair, string $registration): void
    {
        $fqcn = $pair->listener;

        if (! isset($acc[$fqcn])) {
            $acc[$fqcn] = new ListenerLocation(file: null, line: null, queued: false, registration: $registration);
        }

        $key = $pair->event.'::'.$pair->method;
        $acc[$fqcn]->handles[$key] = new ListenerHandle(event: $pair->event, method: $pair->method);

        if ($this->precedence($registration) > $this->precedence($acc[$fqcn]->registration)) {
            $acc[$fqcn]->registration = $registration;
        }
    }

    private function precedence(string $registration): int
    {
        return match ($registration) {
            self::REGISTRATION_SUBSCRIBER => 4,
            self::REGISTRATION_LISTEN_ARRAY => 3,
            self::REGISTRATION_EVENT_LISTEN_CALL => 2,
            self::REGISTRATION_AUTO_DISCOVERED => 1,
            default => 0,
        };
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?ListenerLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        $visitor = new ListenerClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new ListenerLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
                queued: $class->queued,
                registration: self::REGISTRATION_AUTO_DISCOVERED,
            );
        }

        return null;
    }

    /**
     * @param  array<string, ListenerLocation>  $merged
     * @return list<ListenerEntry>
     */
    private function emit(array $merged, ClassHierarchyResolver $resolver): array
    {
        ksort($merged);

        $entries = [];
        foreach ($merged as $fqcn => $loc) {
            $handles = array_values($loc->handles);
            usort($handles, fn (ListenerHandle $a, ListenerHandle $b): int => [$a->event, $a->method] <=> [$b->event, $b->method]);

            // file/line are non-null at emit time — guaranteed by merge()'s PSR-4 guess.
            $entries[] = new ListenerEntry(
                fqcn: $fqcn,
                file: (string) $loc->file,
                line: (int) $loc->line,
                handles: $handles,
                registration: $loc->registration,
                queued: $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_QUEUE->value),
            );
        }

        return $entries;
    }

    /**
     * Dedup by (event, file, line, registration); sort by (event, file, line).
     *
     * @param  list<ClosureListenerRecord>  $listenArrayClosures
     * @param  list<ClosureListenerRecord>  $eventListenClosures
     * @param  list<ClosureListenerRecord>  $subscriberClosures
     * @return list<ClosureListenerEntry>
     */
    private function buildClosureListeners(
        array $listenArrayClosures,
        array $eventListenClosures,
        array $subscriberClosures,
    ): array {
        /** @var array<string, ClosureListenerRecord> $seen */
        $seen = [];

        foreach ([$listenArrayClosures, $eventListenClosures, $subscriberClosures] as $bucket) {
            foreach ($bucket as $entry) {
                $key = $entry->event.'|'.$entry->file.'|'.$entry->line.'|'.$entry->registration;
                $seen[$key] = $entry;
            }
        }

        $records = array_values($seen);
        usort($records, fn (ClosureListenerRecord $a, ClosureListenerRecord $b): int => [$a->event, $a->file, $a->line] <=> [$b->event, $b->file, $b->line]);

        // Cross-link populates dispatches[]; emitting empty here matches the schema.
        return array_map(
            fn (ClosureListenerRecord $r): ClosureListenerEntry => new ClosureListenerEntry(
                event: $r->event,
                file: $r->file,
                line: $r->line,
                registration: $r->registration,
                queued: false,
            ),
            $records,
        );
    }
}
