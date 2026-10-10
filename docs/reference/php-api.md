# Index PHP API

The typed, in-memory counterpart to the [JSON schema](schema.md): the PHP objects you get when you load a written index, for library code and custom tooling. Everything lives in `Lucasp\Loom\Index\` (loader and `Index`) and `Lucasp\Loom\Index\Model\` (value objects).

## Loading an index

`IndexLoader` hydrates an `Index` from a written `index.json`, the inverse of `Index::toArray()`:

```php
use Lucasp\Loom\Index\IndexLoader;

$loader = new IndexLoader();

$index = $loader->fromFile('storage/loom/index.json'); // read + decode a file
$index = $loader->fromJson($jsonString);               // decode a JSON string
$index = $loader->fromArray($decodedArray);            // wrap an already-decoded array
```

### Errors

Every failure throws `Lucasp\Loom\Index\IndexLoadException` (a `RuntimeException`): an unreadable file (`fromFile`), invalid JSON or JSON that isn't an object (`fromJson`), or a missing `loom_version`, `scanned_at` or `laravel_version` envelope field (`fromArray`). The loader does not run schema validation, and an absent section hydrates as an empty list. The envelope scalars are public properties: `$index->loomVersion`, `->scannedAt`, `->laravelVersion`.

## Typed access

Each section has a getter on `Index` returning a `list<X>` of value objects, hydrated lazily and memoized: `events()`, `modelEvents()`, `listeners()`, `closureListeners()`, `observers()`, `jobs()`, `mailables()`, `notifications()`, `scheduledTasks()`, `routes()` and `unresolvedDispatches()`, returning the matching `Model\` class.

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

String-valued schema fields hydrate into typed enums in `Lucasp\Loom\Index\`: `ListenerRegistration`, `ObserverRegistration`, `ScheduleKind`, `FrequencyUnit`, `DispatchKinds` and `Confidence`, with one case per schema value. Read the backing string with `->value`. On a cross-linked `Dispatch`, `kind` is `EVENT` or `JOB`; `AMBIGUOUS` never survives into a written index.

## What is public

Public classes carry `@api` and are listed here:

- `Lucasp\Loom\Index\`: `Index`, `IndexLoader`, `IndexLoadException`, and the enums `Confidence`, `DispatchKinds`, `DispatchMode`, `FrequencyUnit`, `ListenerRegistration`, `ObserverRegistration`, `ScheduleKind`.
- `Lucasp\Loom\Index\Model\`: the value objects above (`ClosureListener`, `Dispatch`, `DispatchOverrides`, `DispatchSite`, `Event`, `Frequency`, `Handle`, `Handler`, `Job`, `Listener`, `Mailable`, `ModelEvent`, `ModelEventHandler`, `Notification`, `Observer`, `QueueConfig`, `Route`, `ScheduledTask`, `UnresolvedDispatch`).

The surface mirrors the [schema](schema.md), so it changes when the schema does.

Every other class carries `@internal` and may change in any release. That includes the scanners and visitors, `Contracts\Scanner`, `IndexBuilder`, the `Dto\*` classes, `Query\IndexQuery`, the check rules and the diff engine. Third-party scanners are not supported: to detect something new, contribute a scanner to Loom itself. The CLI commands, MCP tools and `config/loom.php` are documented in their own references.

A test fails if a class is neither `@internal` nor `@api` and listed on this page.
