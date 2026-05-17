# Schema

Reference for `storage/loom/index.json`. The authoritative definition is `schema/loom-index.schema.json`; this page is prose companion.

## Top-level structure

```
{
  "loom_version": string,        // semver of Loom that produced this index
  "scanned_at": string,           // ISO 8601 UTC timestamp
  "laravel_version": string,      // detected Laravel version of the scanned app
  "stats": object,                // counts by section
  "events": array,                // discovered event classes
  "model_events": array,          // Eloquent model event entries
  "listeners": array,             // discovered listeners
  "closure_listeners": array,     // discovered closure / arrow-function listener registrations
  "observers": array,             // discovered observers
  "unresolved_dispatches": array  // dispatch sites that could not be statically resolved
}
```

All fields are required. Empty arrays are valid. `null` is never valid for an array field. `additionalProperties: false` at the top level — fields beyond these are a schema violation.

## `events[]`

```
{
  "id": string,                   // canonical identifier (FQCN for class events)
  "fqcn": string,                 // fully qualified class name
  "kind": "class",                // only allowed value at this time
  "file": string,                 // path relative to app root, forward slashes
  "line": integer,                // 1-indexed declaration line
  "dispatched_from": array,       // populated by cross-link from DispatchScanner
  "handled_by": array             // populated by cross-link from listeners
}
```

`dispatched_from[]` entry:

```
{
  "file": string,
  "line": integer,
  "method": string                // "ClassName::methodName" of the dispatching context
}
```

`handled_by[]` entry:

```
{
  "listener": string,             // FQCN of the listener class
  "method": string                // handler method on the listener
}
```

Sorted by `listener` ascending, then `method` ascending. A listener with multiple handler methods for the same event contributes one entry per `(listener, method)` pair.

## `model_events[]`

Synthetic entries representing Eloquent model events. Emitted directly by `ObserverScanner` from observer hook discovery and from `Event::listen('eloquent.*', …)` listener strings.

```
{
  "id": string,                   // "eloquent.{hook}: {ModelFQCN}"
  "kind": "model_event",
  "model": string,                // FQCN of the model
  "event": string,                // hook name
  "handled_by": array<string>     // "ObserverFqcn::{hook}" strings, sorted + deduped
}
```

Valid `event` values (the canonical Eloquent hook enum):

```
retrieved, creating, created, updating, updated, saving, saved,
deleting, deleted, restoring, restored, replicating, trashed,
forceDeleting, forceDeleted, booting, booted
```

## `listeners[]`

```
{
  "fqcn": string,
  "file": string,
  "line": integer,
  "handles": array,               // {event, method} pairs this listener handles
  "registration": enum,           // see below
  "queued": boolean,              // true iff class directly implements ShouldQueue
  "dispatches": array             // populated by cross-link from DispatchScanner
}
```

`handles[]` entry:

```
{
  "event": string,                // FQCN of the event class
  "method": string                // handler method on the listener
}
```

`method` is always present. It defaults to `"handle"` when the registration didn't name a method (auto-discovery, bare `Listener::class` in a `$listen` array, bare `Listener::class` as the second argument to `Event::listen()`). Tuple forms `[Listener::class, 'foo']` preserve the method name. Entries are deduped by `(event, method)` and sorted by `event` then `method`.

`registration` enum:

- `subscriber` — discovered via `$subscribe` array or `Event::subscribe(...)`
- `listen_array` — found in the `$listen` array of an `EventServiceProvider`
- `event_listen_call` — registered via `Event::listen()` in a provider's `boot()`
- `auto_discovered` — Laravel 11+ auto-discovery via typed `handle()` parameter

When the same listener is discovered through multiple paths, precedence is `subscriber > listen_array > event_listen_call > auto_discovered`.

`dispatches[]` entry (`$defs/dispatch`):

```
{
  "target": string,               // FQCN of dispatched event or job
  "kind": "event" | "job",
  "confidence": "high" | "medium" | "low",
  "file": string,
  "line": integer
}
```

`confidence` is currently always `"high"` for statically resolved targets. `"medium"` and `"low"` are reserved for future runtime overlay work.

## `closure_listeners[]`

Closure and arrow-function listener registrations. Distinct from `listeners[]` because they have no FQCN to key against — each entry represents a single registration site.

```
{
  "event": string,                // FQCN for ::class registrations, raw string for string-keyed registrations
  "file": string,                 // path to the closure node, not the registration call
  "line": integer,                // 1-indexed line of the closure node
  "registration": enum,           // see below
  "queued": boolean,              // currently always false
  "dispatches": array             // currently always empty; reserved
}
```

`registration` enum:

- `listen_array` — closure value inside the `$listen` array on an `EventServiceProvider`
- `event_listen_call` — closure as the second argument to `Event::listen()`
- `subscriber` — closure value inside a subscriber's `subscribe()` return-array

`queued` is always `false` in the current release; closure-queue detection is out of scope. `dispatches` is always `[]` and is reserved for future line-span-based attribution of dispatch sites inside the closure body.

Entries are sorted by `(file, line, event)` ascending. No dedupe — each registration site is its own entry.

The cross-link pass intentionally does NOT add closure entries to `events[*].handled_by`, because that field's shape is `{listener, method}` and closures have neither. Consumers should filter `closure_listeners[]` by `event` themselves.

## `observers[]`

```
{
  "fqcn": string,
  "file": string,
  "line": integer,
  "observes": string,             // FQCN of the observed model
  "registration": enum,           // "observe_call" | "attribute"
  "hooks": array<string>,         // hook method names declared on the observer
  "dispatches": array             // same shape as listeners.dispatches; cross-link populated
}
```

`registration` enum:

- `observe_call` — `Model::observe(Observer::class)` (including `static::observe(...)` in `booted()`)
- `attribute` — `#[ObservedBy(Observer::class)]` on the model

When the same `(observer, model)` pair is discovered through both paths, precedence is `attribute > observe_call`.

One observer registered against N models produces N entries.

## `unresolved_dispatches[]`

```
{
  "file": string,
  "line": integer,
  "expression": string,           // pretty-printed source of the unresolvable expression
  "reason": enum
}
```

`reason` enum:

- `dynamic_class_name` — `event($variable)` or similar
- `container_resolution` — class fetched from the container at runtime (`app()`, `resolve()`, `->make()`)
- `string_concatenation` — class name built from a string expression (concat or interpolation)
- `conditional_dispatch` — non-resolvable ternary or conditional whose branches can't be evaluated statically

## `stats`

```
{
  "events": integer,
  "listeners": integer,
  "closure_listeners": integer,
  "observers": integer,
  "unresolved_dispatches": integer
}
```

Counts mirror the sizes of the corresponding arrays. `model_events` is intentionally not in `stats` — it's derived data, not a primary discovery output.

## Versioning

`loom_version` follows semver against the schema shape:

- **Patch** — bug fix in scanner output that doesn't change the shape
- **Minor** — additive: new optional fields, new enum values, new sections
- **Major** — breaking: required field removed, type changed, enum value removed

Schema changes go through the `schema-guardian` agent for review and require a CHANGELOG entry calling out the version implication. Pre-1.0, breaking changes are tolerated but not free — they should still be deliberate.
