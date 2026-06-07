# Index PHP API

The typed, in-memory counterpart to the [JSON schema](schema.md). Where the schema
describes the bytes on disk, this page describes the PHP objects you get when you
load those bytes back into a program. It is the supported surface for library
consumers — the read-only browser UI (#19), the MCP server (#20), and any custom
tooling that wants to walk a Loom index without reaching into raw arrays.

Everything here lives in `Lucasp\Loom\Index\` (loader + `Index`) and
`Lucasp\Loom\Index\Model\` (the value objects).

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

`fromFile()` and `fromJson()` ultimately delegate to `fromArray()`, so all three
produce the same `Index`.

### Errors

Every failure throws `Lucasp\Loom\Index\IndexLoadException` (a `RuntimeException`):

| Cause | Entry point | Message shape |
|---|---|---|
| File unreadable / missing | `fromFile` | `Unable to read Loom index file: …` |
| Invalid JSON | `fromJson` | `Loom index is not valid JSON: …` |
| JSON not an object | `fromJson` | `Loom index must decode to a JSON object.` |
| Missing envelope field | `fromArray` | `Loom index is missing the required `…` envelope field.` |

The required envelope fields are `loom_version`, `scanned_at`, and
`laravel_version`. The loader does **not** run schema validation — it trusts that
a file Loom wrote conforms. Absent sections default to an empty list, so an index
that predates a section (e.g. one written before `routes[]` existed) hydrates
cleanly with `routes()` returning `[]` rather than throwing.

The envelope scalars are plain public properties on the result:

```php
$index->loomVersion;     // "0.3.0"
$index->scannedAt;       // "2026-05-16T19:25:54Z"
$index->laravelVersion;  // "13.7"
```

## Typed access

Each section has a getter on `Index` that returns a `list<X>` of read-model value
objects. The lists are hydrated lazily on first call and memoized, so repeated
calls are cheap and you can call as many getters as you need.

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
| `scheduled()` | `list<Model\Scheduled>` |
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

Each `find*` returns `null` when the FQCN is unknown. `dispatchersOf()` and
`handlersOf()` return an empty list for an unknown or unconnected event — they are
convenience wrappers over `findEvent($fqcn)?->dispatchedFrom` and
`?->handledBy`, so they never throw.

### Worked example — walking the graph

Load an index, then trace an event back to the code that dispatches it and forward
to the listeners that handle it:

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

Because the read model is fully typed, your IDE and PHPStan see every field — no
array-key guessing, no `@var` hints at call sites.

## Value objects

Every value object in `Lucasp\Loom\Index\Model\` is `final readonly` with public
properties and a `fromArray()` factory. Fields mirror the JSON schema 1:1, but the
property names are **camelCase** — `dispatched_from` becomes `->dispatchedFrom`,
`queue_config` becomes `->queueConfig`, and so on. Enum-valued fields hydrate into
typed enums (listed below the tables).

### Section models

| Model | Fields |
|---|---|
| `Event` | `string $id`, `string $fqcn`, `string $kind`, `string $file`, `int $line`, `list<DispatchSite> $dispatchedFrom`, `list<Handler> $handledBy` |
| `ModelEvent` | `string $id`, `string $model`, `string $event`, `list<string> $handledBy` |
| `Listener` | `string $fqcn`, `string $file`, `int $line`, `list<Handle> $handles`, `ListenerRegistration $registration`, `bool $queued`, `list<Dispatch> $dispatches` |
| `ClosureListener` | `string $event`, `string $file`, `int $line`, `int $endLine`, `ListenerRegistration $registration`, `bool $queued`, `list<Dispatch> $dispatches` |
| `Observer` | `string $fqcn`, `string $file`, `int $line`, `string $observes`, `ObserverRegistration $registration`, `list<string> $hooks`, `list<Dispatch> $dispatches` |
| `Job` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $dispatchedFrom`, `list<Dispatch> $dispatches` |
| `Mailable` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $sentFrom` |
| `Notification` | `string $fqcn`, `string $file`, `int $line`, `bool $queued`, `?QueueConfig $queueConfig`, `list<DispatchSite> $notifiedFrom`, `list<string> $channels`, `bool $channelsDynamic` |
| `Scheduled` | `ScheduleKind $kind`, `?string $name`, `?string $target`, `list<string> $arguments`, `?string $queue`, `?string $connection`, `?string $cron`, `?Frequency $frequency`, `?string $timezone`, `bool $withoutOverlapping`, `?int $withoutOverlappingExpiresAt`, `bool $onOneServer`, `bool $runInBackground`, `bool $evenInMaintenanceMode`, `list<string> $constraints`, `string $file`, `int $line` |
| `Route` | `string $method`, `string $uri`, `?string $name`, `?string $controllerFqcn`, `?string $controllerMethod`, `list<string> $middleware`, `string $file`, `int $line`, `list<Dispatch> $dispatches` |
| `UnresolvedDispatch` | `string $file`, `int $line`, `string $expression`, `string $reason` |

### Shared models

| Model | Fields |
|---|---|
| `Dispatch` | `string $target`, `DispatchKinds $kind`, `Confidence $confidence`, `string $file`, `int $line` |
| `DispatchSite` | `string $file`, `int $line`, `string $method`, `?DispatchOverrides $overrides`, `?list<string> $channels` |
| `DispatchOverrides` | `?string $locale`, `?string $mailer`, `?string $connection`, `?string $queue`, `?int $delay`, `?bool $afterCommit` |
| `QueueConfig` | `string\|int\|null $connection`, `string\|int\|null $queue`, `string\|int\|null $delay`, `string\|int\|null $tries`, `string\|int\|null $timeout`, `string\|int\|null $backoff` |
| `Frequency` | `FrequencyUnit $unit`, `int $every` — a sub-minute schedule frequency (`scheduled[*].frequency`); present only when `cron` is `null` |
| `Handle` | `string $event`, `string $method` — a listener's event→method binding (`listeners[*].handles`) |
| `Handler` | `string $listener`, `string $method` — an event's listener→method binding (`events[*].handled_by`) |

`DispatchSite::$overrides` is `null` when the call site applied no fluent
modifiers; `DispatchSite::$channels` is `null` except on notification
`notifiedFrom` entries that carry a static channel filter. `Job`, `Mailable`, and
`Notification` carry `$queueConfig === null` when `$queued` is `false`. These
mirror the optional/nullable rules in the [schema](schema.md) exactly.

### Enums

The schema's string-valued fields hydrate into typed enums (all in
`Lucasp\Loom\Index\`):

| Field | Enum | Cases |
|---|---|---|
| `listeners[*].registration`, `closure_listeners[*].registration` | `ListenerRegistration` | `LISTEN_ARRAY`, `AUTO_DISCOVERED`, `EVENT_LISTEN_CALL`, `SUBSCRIBER` |
| `observers[*].registration` | `ObserverRegistration` | `OBSERVE_CALL`, `ATTRIBUTE` |
| `scheduled[*].kind` | `ScheduleKind` | `COMMAND`, `JOB`, `CLOSURE`, `EXEC` |
| `scheduled[*].frequency.unit` | `FrequencyUnit` | `SECONDS` |
| `dispatches[*].kind` | `DispatchKinds` | `EVENT`, `JOB`, `MAILABLE`, `NOTIFICATION`, `AMBIGUOUS` |
| `dispatches[*].confidence` | `Confidence` | `HIGH`, `MEDIUM`, `LOW` |

Read an enum's backing string with `->value` (e.g. `$route->dispatches[0]->kind->value`).
On a cross-linked `Dispatch`, `kind` is always `EVENT` or `JOB` — `AMBIGUOUS` is an
internal pre-disambiguation marker that never survives into a written index.
`confidence` is currently always `HIGH`; `MEDIUM`/`LOW` are reserved for future
runtime-overlay work.

## Stable API vs internal

The consumer API is exactly: the `Index` getters/lookups, `IndexLoader`,
`IndexLoadException`, and the `Index\Model\` value objects plus their enums. This
surface hydrates from the JSON/array shape — the [schema](schema.md) — so it is
decoupled from how the scanners build an index. You can rely on it.

What is **not** part of the consumer API:

- The `Lucasp\Loom\Dto\*Entry` classes. These are internal build inputs that
  visitors emit and scanners consume; they are an implementation detail of the
  scan pipeline, not a consumption format. Do not depend on them.
- `IndexBuilder`, the scanners, the cross-link phases, and the `_dispatch_sites`
  internal section. These produce an index; consumers read one.

If you only ever read a written `index.json`, `IndexLoader` + the read model is
all you need. See [ADR 0005](adr/0005-index-read-model.md) for why the read model
is decoupled from the scanner DTOs and hydrated from the schema shape.
