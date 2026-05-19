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
  "jobs": array,                  // discovered job classes
  "observers": array,             // discovered observers
  "scheduled": array,             // task-scheduler entries
  "mailables": array,             // discovered mailable classes
  "notifications": array,         // discovered notification classes
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

`dispatched_from[]` entry (`$defs/dispatchSite`):

```
{
  "file": string,
  "line": integer,
  "method": string                // "ClassName::methodName" of the dispatching context
}
```

The same `$defs/dispatchSite` shape is referenced by `jobs[*].dispatched_from`. It used to be inline under `events[*].dispatched_from`; the body is unchanged, only the schema reference was promoted.

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

- `subscriber` — discovered via a subscriber's `subscribe()` method. Covers both the return-array form (`return [Event::class => 'method', …]`) and the imperative form (`$events->listen(Event::class, [Listener::class, 'method'])` against the dispatcher parameter). The subscriber itself is registered via `$subscribe` array or `Event::subscribe(...)`. A foreign listener wired imperatively from inside a subscriber's body is also stamped `subscriber`.
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
- `subscriber` — closure inside a subscriber's `subscribe()` method, either as a return-array value or as the second argument to an imperative `$events->listen(...)` call against the dispatcher parameter

`queued` is always `false` in the current release; closure-queue detection is out of scope. `dispatches` is always `[]` and is reserved for future line-span-based attribution of dispatch sites inside the closure body.

Entries are sorted by `(event, file, line)` ascending. No dedupe — each registration site is its own entry.

The cross-link pass intentionally does NOT add closure entries to `events[*].handled_by`, because that field's shape is `{listener, method}` and closures have neither. Consumers should filter `closure_listeners[]` by `event` themselves.

## `jobs[]`

```
{
  "fqcn": string,
  "file": string,
  "line": integer,
  "queued": boolean,              // true iff class directly implements ShouldQueue
  "queue_config": object | null,  // null when queued is false; $defs/queueConfig otherwise
  "dispatched_from": array,       // populated by cross-link; $defs/dispatchSite entries
  "dispatches": array             // populated by cross-link; $defs/dispatch entries
}
```

`$defs/queueConfig` entry:

```
{
  "connection": string | null,
  "queue": string | null,
  "delay": integer | null,
  "tries": integer | null,
  "timeout": integer | null,
  "backoff": integer | null
}
```

All six keys are required when `queue_config` is an object; each value is the scalar literal declared as a class property, or `null` when no such property is declared (the framework default applies at runtime). `queue_config` is `null` (not an empty object) when `queued` is `false`.

`dispatched_from[]` uses `$defs/dispatchSite` (the same shape as `events[*].dispatched_from`). It is populated by the cross-link pass from dispatch sites with finalized `kind === 'job'` whose `target` matches the job's FQCN.

`dispatches[]` uses `$defs/dispatch`. It is populated by the cross-link pass from dispatch sites whose enclosing class is the job and whose enclosing method is literally `handle`.

Entries are sorted by `fqcn` ascending.

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

## `scheduled[]`

Entries declared in Laravel's task scheduler. Emitted by `ScheduleScanner`. One entry per chain.

```
{
  "kind": enum,                   // "command" | "job" | "closure" | "exec"
  "target": string | null,        // depends on kind; see below
  "cron": string | null,          // five-field cron expression
  "timezone": string | null,      // from ->timezone(...)
  "without_overlapping": boolean,
  "on_one_server": boolean,
  "run_in_background": boolean,
  "constraints": array<string>,   // opaque labels for non-cron restrictions, sorted ascending
  "file": string,                 // path to the root call (->command/->job/->call/->exec)
  "line": integer
}
```

`$defs/scheduleEntry`. All fields are required. `target` and `cron` may be `null`; `timezone` may be `null`.

`kind` is determined by the chain's root call:

- `command` — `->command(string|FQCN)`. `target` is the signature string verbatim (`"mail:send"`, `"mail:send {--queue=default}"`), or the FQCN when the argument was a `::class` constant.
- `job` — `->job(new X)` or `->job(X::class)`. `target` is the FQCN.
- `closure` — `->call(...)`. `target` is `null` for inline closures, `"FQCN::method"` for tuple callables (`[Cls::class, 'method']`) and Laravel callable strings (`'App\\Cls@method'`).
- `exec` — `->exec(string)`. `target` is the shell command string.

`cron` is the canonical five-field expression for every recognised frequency helper (`daily` → `"0 0 * * *"`, `everyFiveMinutes` → `"*/5 * * * *"`, `cron('*/5 8-17 * * 1-5')` passed through verbatim). It is `null` when no recognised helper appears, when the helper's argument is a variable, or when the last frequency helper in the chain is unrecognised. The recognised set is enumerated in [ADR 0002 §3](adr/0002-schedule-scanner.md). When multiple frequency helpers chain together, last wins (mirroring Laravel's runtime).

`constraints[]` carries opaque labels for non-cron restrictions: day-of-week (`"weekdays"`, `"sundays"`, …), time-window (`"between(8:00,17:00)"`, `"unlessBetween(...)"`), conditional (`"when(closure)"`, `"skip(closure)"`), and environment (`"environments(production,staging)"`). Sorted ascending. Constraints are emitted in addition to `cron` because Laravel evaluates them at runtime alongside the cron tick — they are not folded into the expression.

`file` and `line` point to the root method call of the chain (`$schedule->command(...)`), not the trailing modifier.

Entries are sorted by `(file, line)` ascending. Deduplication is on `(file, line, kind, target)`; merging across kernel / bootstrap / facade discovery favours kernel and bootstrap forms over facade.

Cross-link is one-directional: `scheduled[*].target` with `kind: "job"` carries a job FQCN that consumers can join against `jobs[*].fqcn` client-side. There is no `jobs[*].scheduled` back-pointer — see [ADR 0002 §5](adr/0002-schedule-scanner.md) for rationale.

See [docs/scanners/schedule.md](scanners/schedule.md) for behaviour details and known limitations.

## `mailables[]`

Mailable classes discovered by `MailableScanner`. One entry per FQCN.

```
{
  "fqcn": string,
  "file": string,
  "line": integer,
  "queued": boolean,              // transitively implements ShouldQueue (resolver-backed)
  "queue_config": object | null,  // null when queued is false; $defs/queueConfig otherwise
  "sent_from": array              // populated by cross-link; $defs/dispatchSite entries
}
```

`$defs/mailable`. All fields are required. `queue_config` uses the same `$defs/queueConfig` six-field shape as `jobs[*].queue_config` (`connection`, `queue`, `delay`, `tries`, `timeout`, `backoff`, each a scalar literal from a class property or `null` when not declared).

`sent_from[]` uses `$defs/dispatchSite` (same shape as `events[*].dispatched_from` and `jobs[*].dispatched_from`). It is populated by the cross-link pass from dispatch sites with finalized `kind === 'mailable'` whose `target` matches the mailable's FQCN. Sorted by `(file, line)`.

Entries are sorted by `fqcn` ascending.

See [docs/scanners/mailables.md](scanners/mailables.md) for discovery paths and known limitations.

## `notifications[]`

Notification classes discovered by `NotificationScanner`. One entry per FQCN.

```
{
  "fqcn": string,
  "file": string,
  "line": integer,
  "queued": boolean,
  "queue_config": object | null,  // null when queued is false; $defs/queueConfig otherwise
  "channels": array<string>,      // from a statically resolvable via() literal; source order
  "channels_dynamic": boolean,    // true only when via() exists but isn't statically resolvable
  "notified_from": array          // populated by cross-link; $defs/dispatchSite entries
}
```

`$defs/notification`. All fields are required.

`channels[]` is extracted from a `via()` method whose body is a single `return [...];` of literal strings (stored lowercased: `"mail"`, `"database"`, `"slack"`, `"broadcast"`, `"vonage"`) and / or `Class::class` constants (stored as FQCN: `"Illuminate\\Notifications\\Channels\\SlackChannel"`). Items are emitted in **source order** — Laravel dispatches over them in declaration order, and preserving source order keeps the index honest to what the file says.

`channels_dynamic` distinguishes:

- `true` — `via()` exists but its body isn't the recognised single-return-literal-array shape (conditional return, property access, variable items, keyed entries, missing). Channels exist at runtime; static analysis can't see them. `channels: []`.
- `false` — either `via()` resolved to a literal channel list (in which case `channels[]` is populated), or `via()` is not declared on the class at all (in which case `channels: []` — an intentional zero, not unknown). Method-level resolution across parents / traits is out of scope (per [ADR 0001 §3](adr/0001-class-hierarchy-resolver.md)), so an inherited `via()` reads as "no `via()` declared".

`notified_from[]` uses `$defs/dispatchSite`. Populated by the cross-link pass from dispatch sites with finalized `kind === 'notification'` whose `target` matches the notification's FQCN. Sorted by `(file, line)`.

Entries are sorted by `fqcn` ascending.

See [docs/scanners/notifications.md](scanners/notifications.md) for discovery paths and known limitations.

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
  "jobs": integer,
  "observers": integer,
  "scheduled": integer,
  "mailables": integer,
  "notifications": integer,
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
