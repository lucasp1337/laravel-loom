# Index PHP API

The typed, in-memory counterpart to the [JSON schema](schema.md). Where the schema describes the bytes on disk, this page describes the PHP objects you get when you load those bytes back into a program. Use it from library code or custom tooling that wants to walk a Loom index without reaching into raw arrays.

Everything here lives in `Lucasp\Loom\Index\` (loader and `Index`) and `Lucasp\Loom\Index\Model\` (the value objects).

## Loading an index

`IndexLoader` hydrates an `Index` from a written `index.json`, the inverse of `Index::toArray()`. Three entry points, depending on what you already hold:

```php
use Lucasp\Loom\Index\IndexLoader;

$loader = new IndexLoader();

$index = $loader->fromFile('storage/loom/index.json'); // read + decode a file
$index = $loader->fromJson($jsonString);               // decode a JSON string
$index = $loader->fromArray($decodedArray);            // wrap an already-decoded array
```

The envelope scalars are plain public properties on the result:

```php
$index->schemaVersion;   // "1.0"
$index->loomVersion;     // "0.4.0"
$index->scannedAt;       // "2026-05-16T19:25:54Z"
$index->laravelVersion;  // "13.7"
```

### Errors

Every failure throws `Lucasp\Loom\Index\IndexLoadException` (a `RuntimeException`):

| Cause | Entry point | Message |
| --- | --- | --- |
| File unreadable or missing | `fromFile` | `Unable to read Loom index file: …` |
| Invalid JSON | `fromJson` | `Loom index is not valid JSON: …` |
| JSON is not an object | `fromJson` | `Loom index must decode to a JSON object.` |
| `schema_version` missing, or its major differs from the one this Loom reads | `fromArray` | ``Loom index has no `schema_version` …``, or `Loom index uses schema … older/newer than the supported …` |
| `loom_version`, `scanned_at` or `laravel_version` missing | `fromArray` | ``Loom index is missing the required `…` envelope field.`` |

The loader does not run schema validation: it trusts that a file Loom wrote conforms. An absent section hydrates as an empty list, so an index that predates a section (for example one written before `routes[]` existed) loads with `routes()` returning `[]`.

## Typed access

Each section has a getter on `Index` that returns a `list<X>` of read-model value objects. The lists are hydrated lazily on first call and memoized, so repeated calls are cheap.

| Getter | Returns |
| --- | --- |
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
| `unresolvedRoutes()` | `list<Model\UnresolvedRoute>` |

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

Each `find*` returns `null` when the FQCN is unknown. `dispatchersOf()` and `handlersOf()` return an empty list for an unknown or unconnected event, so they never throw.

### Walking the graph

Load an index, then trace an event back to the code that dispatches it and forward to the listeners that handle it:

```php
use Lucasp\Loom\Index\IndexLoader;

$index = (new IndexLoader())->fromFile('storage/loom/index.json');

foreach ($index->events() as $event) {
    echo "{$event->fqcn} ({$event->file}:{$event->line})\n";

    foreach ($index->dispatchersOf($event->fqcn) as $site) {
        echo "  dispatched from {$site->method} at {$site->file}:{$site->line}\n";
    }

    foreach ($index->handlersOf($event->fqcn) as $handler) {
        echo "  handled by {$handler->listener}::{$handler->method}\n";
    }
}
```

Because the read model is fully typed, your IDE and PHPStan see every field: no array-key guessing, no `@var` hints at call sites.

## Value objects

Every value object in `Lucasp\Loom\Index\Model\` is `final readonly` with public properties and a `fromArray()` factory. Fields mirror the JSON schema one to one, but the property names are camelCase: `dispatched_from` becomes `->dispatchedFrom`, `queue_config` becomes `->queueConfig`. Enum-valued fields hydrate into typed enums, listed under [Enums](#enums).

!!! note "Nullable fields"
    - `DispatchSite::$mode` is `null` for a plain dispatch; it is set only for the sync, after-response and queue-push forms.
    - `DispatchSite::$overrides` is `null` when the call site applied no fluent modifiers.
    - `DispatchSite::$channels` is `null` except on notification `notifiedFrom` entries that carry a static channel filter.
    - `Job`, `Mailable` and `Notification` carry `$queueConfig === null` when `$queued` is `false`.

    These mirror the optional and nullable rules in the [schema](schema.md) exactly.

### Section models

| Model | Fields |
| --- | --- |
| `Event` | `string $id`, `string $fqcn`, `string $kind`, `string $file`, `int $line`, `list<DispatchSite> $dispatchedFrom`, `list<Handler> $handledBy` |
| `ModelEvent` | `string $id`, `string $model`, `string $event`, `list<ModelEventHandler> $handledBy` |
| `Listener` | `string $fqcn`, `string $file`, `int $line`, `list<Handle> $handles`, `ListenerRegistration $registration`, `bool $queued`, `list<Dispatch> $dispatches` |
| `ClosureListener` | `string $event`, `string $file`, `int $line`, `int $endLine`, `ListenerRegistration $registration`, `bool $queued`, `list<Dispatch> $dispatches` |
| `Observer` | `string $fqcn`, `string $file`, `int $line`, `string $observes`, `ObserverRegistration $registration`, `list<string> $hooks`, `list<Dispatch> $dispatches` |
| `Job` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $dispatchedFrom`, `list<Dispatch> $dispatches` |
| `Mailable` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $sentFrom` |
| `Notification` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $notifiedFrom`, `list<string> $channels`, `bool $channelsDynamic` |
| `ScheduledTask` | `ScheduleKind $kind`, `?string $name`, `?string $target`, `list<string> $arguments`, `?string $queue`, `?string $connection`, `?string $cron`, `?Frequency $frequency`, `?string $timezone`, `bool $withoutOverlapping`, `?int $withoutOverlappingExpiresAt`, `bool $onOneServer`, `bool $runInBackground`, `bool $evenInMaintenanceMode`, `list<string> $constraints`, `string $file`, `int $line` |
| `Route` | `string $method`, `string $uri`, `?string $name`, `?string $controllerFqcn`, `?string $controllerMethod`, `list<string> $middleware`, `string $file`, `int $line`, `list<Dispatch> $dispatches`, `?int $endLine`, `?string $kind` |
| `UnresolvedDispatch` | `string $file`, `int $line`, `string $expression`, `string $reason` |
| `UnresolvedRoute` | `string $file`, `int $line`, `string $expression`, `string $reason` |

### Shared models

| Model | Fields |
| --- | --- |
| `Dispatch` | `string $target`, `DispatchKinds $kind`, `Confidence $confidence`, `string $file`, `int $line` |
| `DispatchSite` | `string $file`, `int $line`, `string $method`, `?DispatchMode $mode`, `?DispatchOverrides $overrides`, `?list<string> $channels` |
| `DispatchOverrides` | `?string $locale`, `?string $mailer`, `?string $connection`, `?string $queue`, `?int $delay`, `?bool $afterCommit` |
| `QueueConfig` | `string\|int\|null $connection`, `string\|int\|null $queue`, `string\|int\|null $delay`, `string\|int\|null $tries`, `string\|int\|null $timeout`, `string\|int\|null $backoff` |
| `Frequency` | `FrequencyUnit $unit`, `int $every`: a sub-minute schedule frequency (`scheduled_tasks[*].frequency`), present only when `cron` is `null` |
| `Handle` | `string $event`, `string $method`: a listener's event-to-method binding (`listeners[*].handles`) |
| `Handler` | `string $listener`, `string $method`: an event's listener-to-method binding (`events[*].handled_by`) |
| `ModelEventHandler` | `string $handler`, `string $method`, `string $file`, `int $line`: an observer hook or `Event::listen` target on `model_events[*].handled_by` |

### Enums

The schema's string-valued fields hydrate into typed enums, all in `Lucasp\Loom\Index\`:

| Field | Enum | Cases |
| --- | --- | --- |
| `listeners[*].registration`, `closure_listeners[*].registration` | `ListenerRegistration` | `LISTEN_ARRAY`, `AUTO_DISCOVERED`, `EVENT_LISTEN_CALL`, `SUBSCRIBER` |
| `observers[*].registration` | `ObserverRegistration` | `OBSERVE_CALL`, `ATTRIBUTE` |
| `scheduled_tasks[*].kind` | `ScheduleKind` | `COMMAND`, `JOB`, `CLOSURE`, `EXEC` |
| `scheduled_tasks[*].frequency.unit` | `FrequencyUnit` | `SECONDS` |
| `dispatches[*].kind` | `DispatchKinds` | `EVENT`, `JOB`, `MAILABLE`, `NOTIFICATION`, `AMBIGUOUS` |
| `dispatches[*].confidence` | `Confidence` | `HIGH`, `MEDIUM`, `LOW` |
| `dispatched_from[*].mode`, `sent_from[*].mode`, `notified_from[*].mode` | `DispatchMode` | `SYNC`, `AFTER_RESPONSE`, `PUSH` |

Read an enum's backing string with `->value` (for example `$route->dispatches[0]->kind->value`). On a cross-linked `Dispatch`, `kind` is always `EVENT` or `JOB`; `AMBIGUOUS` never survives into a written index. `confidence` is currently always `HIGH`; `MEDIUM` and `LOW` are reserved.

## What is public

Public classes carry `@api` and are listed here:

- `Lucasp\Loom\Index\`: `Index`, `IndexLoader`, `IndexLoadException`, and the enums `Confidence`, `DispatchKinds`, `DispatchMode`, `FrequencyUnit`, `ListenerRegistration`, `ObserverRegistration`, `ScheduleKind`.
- `Lucasp\Loom\Index\Model\`: the value objects above (`ClosureListener`, `Dispatch`, `DispatchOverrides`, `DispatchSite`, `Event`, `Frequency`, `Handle`, `Handler`, `Job`, `Listener`, `Mailable`, `ModelEvent`, `ModelEventHandler`, `Notification`, `Observer`, `QueueConfig`, `Route`, `ScheduledTask`, `UnresolvedDispatch`, `UnresolvedRoute`).

The surface mirrors the [schema](schema.md), so it changes when the schema does.

Every other class carries `@internal` and may change in any release. That includes the scanners and visitors, `Contracts\Scanner`, `IndexBuilder`, the `Dto\*` classes, `Query\IndexQuery`, the check rules and the diff engine. Third-party scanners are not supported: to detect something new, contribute a scanner to Loom itself. The CLI commands, MCP tools and configuration are documented in [Commands](commands.md), [MCP tools](mcp-tools.md) and [Scan configuration](scan-config.md).
