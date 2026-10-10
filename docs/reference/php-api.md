# Index PHP API

The typed, in-memory counterpart to the [JSON schema](schema.md): the PHP objects you get when you load a written index, for library code and custom tooling. Everything lives in `Lucasp\Loom\Index\` (loader and `Index`) and `Lucasp\Loom\Index\Model\` (value objects).

## Loading an index

`IndexLoader` hydrates an `Index` from a written `index.json` — the inverse of
`Index::toArray()`. Three entry points, depending on what you already hold:

```php
use Lucasp\Loom\Index\IndexLoader;

$loader = new IndexLoader();

$index = $loader->fromFile('storage/loom/index.json'); // read + decode a file
$index = $loader->fromJson($jsonString);               // decode a JSON string
$index = $loader->fromArray($decodedArray);            // wrap an already-decoded array
```

### Errors

Every failure throws `Lucasp\Loom\Index\IndexLoadException` (a `RuntimeException`):

| Cause | Entry point | Message shape |
|---|---|---|
| File unreadable / missing | `fromFile` | `Unable to read Loom index file: …` |
| Invalid JSON | `fromJson` | `Loom index is not valid JSON: …` |
| JSON not an object | `fromJson` | `Loom index must decode to a JSON object.` |
| Missing envelope field | `fromArray` | `Loom index is missing the required `…` envelope field.` |

The required envelope fields are `loom_version`, `scanned_at` and `laravel_version`. The loader does not run schema validation, and an absent section hydrates as an empty list.

The envelope scalars are plain public properties on the result:

```php
$index->loomVersion;     // "0.4.0"
$index->scannedAt;       // "2026-05-16T19:25:54Z"
$index->laravelVersion;  // "13.7"
```

## Typed access

Each section has a getter on `Index` returning a `list<X>` of value objects, hydrated lazily and memoized.

| Getter | Returns |
|---|---|
| `events()` | `list<Model\Event>` |
| `modelEvents()` | `list<Model\ModelEvent>` |
| `listeners()` | `list<Model\Listener>` |
| `closureListeners()` | `list<Model\ClosureListener>` |
| `observers()` | `list<Model\Observer>` |
| `jobs()` | `list<Model\Job>` |
| `mailables()` | `list<Model\Mailable>` |
| `notifications()` | `list<Model\Notification>` |
| `scheduledTasks()` | `list<Model\ScheduledTask>` |
| `routes()` | `list<Model\Route>` |
| `unresolvedDispatches()` | `list<Model\UnresolvedDispatch>` |

### Lookups

`Index` also exposes by-FQCN lookups and two graph helpers:

```php
$index->findEvent('App\\Events\\OrderShipped');             // ?Model\Event
$index->findListener('App\\Listeners\\NotifyWarehouse');    // ?Model\Listener
$index->findObserver('App\\Observers\\UserObserver');       // ?Model\Observer
$index->findJob('App\\Jobs\\ProcessShipment');              // ?Model\Job
$index->findMailable('App\\Mail\\ShipmentDispatched');      // ?Model\Mailable
$index->findNotification('App\\Notifications\\OrderShipped'); // ?Model\Notification

$index->dispatchersOf('App\\Events\\OrderShipped'); // list<Model\DispatchSite>
$index->handlersOf('App\\Events\\OrderShipped');    // list<Model\Handler>
```

Each `find*` returns `null` for an unknown FQCN. `dispatchersOf()` and `handlersOf()` return an empty list for an unknown or unconnected event and never throw.

### Walking the graph

```php
$index = (new IndexLoader())->fromFile('storage/loom/index.json');

foreach ($index->events() as $event) {
    foreach ($index->dispatchersOf($event->fqcn) as $site) {
        echo "{$event->fqcn} dispatched from {$site->method} at {$site->file}:{$site->line}\n";
    }
    foreach ($index->handlersOf($event->fqcn) as $handler) {
        echo "{$event->fqcn} handled by {$handler->listener}::{$handler->method}\n";
    }
}
```

## Value objects

Every class in `Lucasp\Loom\Index\Model\` is `final readonly` with public properties and a `fromArray()` factory. Fields mirror the [schema](schema.md) one to one with camelCase names (`dispatched_from` is `->dispatchedFrom`, `queue_config` is `->queueConfig`), and nullability matches it exactly: `DispatchSite::$overrides` is `null` when no modifier was applied, `DispatchSite::$channels` is `null` except on notification entries with a static channel filter, and `$queueConfig` is `null` when `$queued` is `false`.

The section models are `Event`, `ModelEvent`, `Listener`, `ClosureListener`, `Observer`, `Job`, `Mailable`, `Notification`, `ScheduledTask`, `Route` and `UnresolvedDispatch`. The shared ones are:

| Model | Fields |
|---|---|
| `Dispatch` | `string $target`, `DispatchKinds $kind`, `Confidence $confidence`, `string $file`, `int $line` |
| `DispatchSite` | `string $file`, `int $line`, `string $method`, `?DispatchOverrides $overrides`, `?list<string> $channels` |
| `DispatchOverrides` | `?string $locale`, `?string $mailer`, `?string $connection`, `?string $queue`, `?int $delay`, `?bool $afterCommit` |
| `QueueConfig` | `connection`, `queue`, `delay`, `tries`, `timeout`, `backoff`, each `string\|int\|null` |
| `Frequency` | `FrequencyUnit $unit`, `int $every`; present only when `cron` is `null` |
| `Handle` | `string $event`, `string $method`: a listener's binding (`listeners[].handles`) |
| `Handler` | `string $listener`, `string $method`: an event's binding (`events[].handled_by`) |
| `ModelEventHandler` | `string $handler`, `string $method`, `string $file`, `int $line` (`model_events[].handled_by`) |

### Enums

The schema's string-valued fields hydrate into typed enums (all in
`Lucasp\Loom\Index\`):

| Field | Enum | Cases |
|---|---|---|
| `listeners[*].registration`, `closure_listeners[*].registration` | `ListenerRegistration` | `LISTEN_ARRAY`, `AUTO_DISCOVERED`, `EVENT_LISTEN_CALL`, `SUBSCRIBER` |
| `observers[*].registration` | `ObserverRegistration` | `OBSERVE_CALL`, `ATTRIBUTE` |
| `scheduled_tasks[*].kind` | `ScheduleKind` | `COMMAND`, `JOB`, `CLOSURE`, `EXEC` |
| `scheduled_tasks[*].frequency.unit` | `FrequencyUnit` | `SECONDS` |
| `dispatches[*].kind` | `DispatchKinds` | `EVENT`, `JOB`, `MAILABLE`, `NOTIFICATION`, `AMBIGUOUS` |
| `dispatches[*].confidence` | `Confidence` | `HIGH`, `MEDIUM`, `LOW` |

Read the backing string with `->value`. On a cross-linked `Dispatch`, `kind` is `EVENT` or `JOB`; `AMBIGUOUS` never survives into a written index. `confidence` is always `HIGH` today.

## What is public

Public classes carry `@api` and are listed here:

- `Lucasp\Loom\Index\`: `Index`, `IndexLoader`, `IndexLoadException`, and the enums `Confidence`, `DispatchKinds`, `DispatchMode`, `FrequencyUnit`, `ListenerRegistration`, `ObserverRegistration`, `ScheduleKind`.
- `Lucasp\Loom\Index\Model\`: the value objects above (`ClosureListener`, `Dispatch`, `DispatchOverrides`, `DispatchSite`, `Event`, `Frequency`, `Handle`, `Handler`, `Job`, `Listener`, `Mailable`, `ModelEvent`, `ModelEventHandler`, `Notification`, `Observer`, `QueueConfig`, `Route`, `ScheduledTask`, `UnresolvedDispatch`).

The surface mirrors the [schema](schema.md), so it changes when the schema does.

Every other class carries `@internal` and may change in any release. That includes the scanners and visitors, `Contracts\Scanner`, `IndexBuilder`, the `Dto\*` classes, `Query\IndexQuery`, the check rules and the diff engine. Third-party scanners are not supported: to detect something new, contribute a scanner to Loom itself. The CLI commands, MCP tools and `config/loom.php` are documented in their own references.

A test fails if a class is neither `@internal` nor `@api` and listed on this page.
