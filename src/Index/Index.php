<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Index\Model\ClosureListener;
use Lucasp\Loom\Index\Model\DispatchSite;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Handler;
use Lucasp\Loom\Index\Model\Job;
use Lucasp\Loom\Index\Model\Listener;
use Lucasp\Loom\Index\Model\Mailable;
use Lucasp\Loom\Index\Model\ModelEvent;
use Lucasp\Loom\Index\Model\Notification;
use Lucasp\Loom\Index\Model\Observer;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Model\Scheduled;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;

/**
 * Immutable Loom index and the stable, documented consumption surface.
 *
 * Two ways in: built in-process by {@see IndexBuilder}, or hydrated from a
 * written `index.json` by {@see IndexLoader}. Either way the typed getters and
 * lookups below return read-model value objects from {@see Model}, so consumers
 * (UI, MCP server, custom tooling) never reach into raw arrays. {@see toArray()}
 * remains the inverse used for JSON serialization.
 */
final class Index
{
    /**
     * Lazily-hydrated, memoized read-model lists, keyed by section name.
     *
     * @var array<string, array<int, object>>
     */
    private array $models = [];

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections  keyed by section name (Sections::value)
     */
    public function __construct(
        public readonly string $loomVersion,
        public readonly string $scannedAt,
        public readonly string $laravelVersion,
        public readonly array $sections = [],
    ) {
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->hydrate(Sections::EVENTS, Event::fromArray(...));
    }

    /** @return list<ModelEvent> */
    public function modelEvents(): array
    {
        return $this->hydrate(Sections::MODEL_EVENTS, ModelEvent::fromArray(...));
    }

    /** @return list<Listener> */
    public function listeners(): array
    {
        return $this->hydrate(Sections::LISTENERS, Listener::fromArray(...));
    }

    /** @return list<ClosureListener> */
    public function closureListeners(): array
    {
        return $this->hydrate(Sections::CLOSURE_LISTENERS, ClosureListener::fromArray(...));
    }

    /** @return list<Observer> */
    public function observers(): array
    {
        return $this->hydrate(Sections::OBSERVERS, Observer::fromArray(...));
    }

    /** @return list<Job> */
    public function jobs(): array
    {
        return $this->hydrate(Sections::JOBS, Job::fromArray(...));
    }

    /** @return list<Mailable> */
    public function mailables(): array
    {
        return $this->hydrate(Sections::MAILABLES, Mailable::fromArray(...));
    }

    /** @return list<Notification> */
    public function notifications(): array
    {
        return $this->hydrate(Sections::NOTIFICATIONS, Notification::fromArray(...));
    }

    /** @return list<Scheduled> */
    public function scheduled(): array
    {
        return $this->hydrate(Sections::SCHEDULED, Scheduled::fromArray(...));
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->hydrate(Sections::ROUTES, Route::fromArray(...));
    }

    /** @return list<UnresolvedDispatch> */
    public function unresolvedDispatches(): array
    {
        return $this->hydrate(Sections::UNRESOLVED_DISPATCHES, UnresolvedDispatch::fromArray(...));
    }

    public function findEvent(string $fqcn): ?Event
    {
        foreach ($this->events() as $event) {
            if ($event->fqcn === $fqcn) {
                return $event;
            }
        }

        return null;
    }

    public function findListener(string $fqcn): ?Listener
    {
        foreach ($this->listeners() as $listener) {
            if ($listener->fqcn === $fqcn) {
                return $listener;
            }
        }

        return null;
    }

    public function findObserver(string $fqcn): ?Observer
    {
        foreach ($this->observers() as $observer) {
            if ($observer->fqcn === $fqcn) {
                return $observer;
            }
        }

        return null;
    }

    public function findJob(string $fqcn): ?Job
    {
        foreach ($this->jobs() as $job) {
            if ($job->fqcn === $fqcn) {
                return $job;
            }
        }

        return null;
    }

    public function findMailable(string $fqcn): ?Mailable
    {
        foreach ($this->mailables() as $mailable) {
            if ($mailable->fqcn === $fqcn) {
                return $mailable;
            }
        }

        return null;
    }

    public function findNotification(string $fqcn): ?Notification
    {
        foreach ($this->notifications() as $notification) {
            if ($notification->fqcn === $fqcn) {
                return $notification;
            }
        }

        return null;
    }

    /**
     * Sites that dispatch the given event, or an empty list when the event is
     * unknown or never dispatched.
     *
     * @return list<DispatchSite>
     */
    public function dispatchersOf(string $eventFqcn): array
    {
        $event = $this->findEvent($eventFqcn);

        return $event === null ? [] : $event->dispatchedFrom;
    }

    /**
     * Listeners that handle the given event, or an empty list when the event is
     * unknown or unhandled.
     *
     * @return list<Handler>
     */
    public function handlersOf(string $eventFqcn): array
    {
        $event = $this->findEvent($eventFqcn);

        return $event === null ? [] : $event->handledBy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stats = [];
        foreach (SectionRegistry::statsNames() as $name) {
            $stats[$name] = count($this->sections[$name] ?? []);
        }

        $payload = [
            MetaField::LOOM_VERSION->value => $this->loomVersion,
            MetaField::SCANNED_AT->value => $this->scannedAt,
            MetaField::LARAVEL_VERSION->value => $this->laravelVersion,
            MetaField::STATS->value => $stats,
        ];

        foreach (SectionRegistry::names() as $name) {
            $payload[$name] = $this->sections[$name] ?? [];
        }

        return $payload;
    }

    /**
     * Hydrate (once) and return a section's read-model value objects.
     *
     * @template T of object
     *
     * @param  callable(array<string, mixed>): T  $factory
     * @return list<T>
     */
    private function hydrate(Sections $section, callable $factory): array
    {
        $key = $section->value;
        if (! array_key_exists($key, $this->models)) {
            $this->models[$key] = array_map($factory, array_values($this->sections[$key] ?? []));
        }

        /** @var list<T> $cached */
        $cached = $this->models[$key];

        return $cached;
    }
}
