# Schema

Reference for `storage/loom/index.json`. The authoritative definition is `schema/loom-index.schema.json`; this page is prose companion. For consuming an index from PHP, the [Index PHP API](php-api.md) describes the typed read model that hydrates from this shape.

## Top-level structure

```
{
  "schema_version": string,       // "MAJOR.MINOR" of this document shape, e.g. "1.0"
  "loom_version": string,        // semver of Loom that produced this index (informational)
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
  "routes": array,                // registered HTTP routes
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
  "method": string,               // "ClassName::methodName" of the dispatching context
  "overrides": object,            // optional; $defs/dispatchOverrides; omitted when empty
  "channels": array<string>       // optional; notification-only; omitted when no static channel filter
}
```

The same `$defs/dispatchSite` shape is referenced by `jobs[*].dispatched_from`, `mailables[*].sent_from`, and `notifications[*].notified_from` — it's the single source of truth for a dispatch site. It used to be inline under `events[*].dispatched_from`; the `{file, line, method}` body is unchanged, only the schema reference was promoted.

`overrides` (`$defs/dispatchOverrides`) records statically-resolvable fluent modifiers applied at the dispatch site. It is **optional**: the key is present only when at least one modifier was found, and is omitted entirely otherwise — so a site with no modifiers has no `overrides` key. Adding it was a non-breaking additive change.

```
{
  "locale": string,               // ->locale('es')
  "mailer": string,               // ->mailer('ses')
  "connection": string,           // ->onConnection('redis')
  "queue": string,                // ->onQueue('high')
  "delay": integer,               // ->delay(60); seconds, minimum 0
  "after_commit": boolean         // ->afterCommit(); only ever true
}
```

All `overrides` keys are optional; only keys for modifiers actually found are emitted, in the order shown above. Events technically carry the same `$defs/dispatchSite` and so may carry `overrides`, but event dispatches rarely use these modifiers in practice. `delay` captures integer-second literals only — a non-literal argument (`->delay(now()->addMinutes(5))`, `->delay($seconds)`) leaves the key absent. See [dispatches scanner](../guides/why-was-my-code-missed.md) for the exact capture rules and limitations.

`channels` (`$defs/dispatchSite.channels`) records a per-dispatch-site channel filter — the third argument to `Notification::send($users, $notification, $channels)` / `sendNow(...)`, which restricts that dispatch to a specific channel set and overrides the notification's own `via()`. It is **optional** and **notification-only**: although it lives on the shared `$defs/dispatchSite`, the producer emits it only on `notified_from[]` entries and never on `dispatched_from[]` (events, jobs) or `sent_from[]` (mailables). Values mirror the `notifications[*].channels[]` shape — lowercased channel names (`"mail"`, `"database"`) and / or custom channel-class FQCNs (`"App\\Channels\\SmsChannel"`). The key is present only when a static channel filter was found at the call site; it is omitted entirely otherwise (no `channels` key — never an empty array; the schema enforces `minItems: 1`). Adding it was a non-breaking additive change.

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
  "handled_by": array<object>     // {handler, method, file, line}, sorted by handler::method, deduped
}
```

`handled_by[]` entry:

```
{
  "handler": string,              // FQCN of the observer or Event::listen target
  "method": string,               // observer hook or listener method
  "file": string,                 // observer class file, or the file holding the Event::listen call
  "line": integer                 // observer class line, or the Event::listen call line
}
```

The entry shape differs from `events[*].handled_by` (`{listener, method}`) on purpose: model-event handlers include observers, which are not listeners.

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
  "line": integer,                // 1-indexed start line of the closure node
  "end_line": integer,            // 1-indexed end line of the closure node
  "registration": enum,           // see below
  "queued": boolean,              // currently always false
  "dispatches": array             // populated by cross-link; $defs/dispatch entries
}
```

`registration` enum:

- `listen_array` — closure value inside the `$listen` array on an `EventServiceProvider`
- `event_listen_call` — closure as the second argument to `Event::listen()`
- `subscriber` — closure inside a subscriber's `subscribe()` method, either as a return-array value or as the second argument to an imperative `$events->listen(...)` call against the dispatcher parameter

`line` and `end_line` together describe the closure node's source span `[line, end_line]` (both 1-indexed, inclusive). The producer always knows both bounds, so both are **required**. The cross-link pass uses this span to attribute dispatch sites to the closure: a dispatch site in the same `file` whose own line falls within `[line, end_line]` is attributed to this closure listener. A single-line closure has `end_line === line`.

`queued` is always `false` in the current release; closure-queue detection is out of scope.

`dispatches[]` uses `$defs/dispatch` — the **same shape** as `listeners[*].dispatches` and `observers[*].dispatches`:

```
{
  "target": string,               // FQCN of dispatched event or job
  "kind": "event" | "job",
  "confidence": "high" | "medium" | "low",
  "file": string,
  "line": integer
}
```

It is populated by the cross-link pass from dispatch sites that fall within the closure's `[line, end_line]` span in the same `file`. This makes closure listeners feature-equivalent to class listeners for dispatch attribution. Earlier releases declared `dispatches` as `array<string>` and always emitted it empty, so no real data ever matched the old item type; the change to `$defs/dispatch` objects is the corrected, populated shape. `confidence` is currently always `"high"`; `"medium"` / `"low"` are reserved for future runtime overlay work.

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
  "name": string | null,          // from ->name(...); null when not named
  "target": string | null,        // depends on kind; see below
  "arguments": array<string>,     // ->command(Class, [...]) parameters; [] for non-command kinds
  "queue": string | null,         // job kind only; ->job($job, $queue) override
  "connection": string | null,    // job kind only; ->job($job, $queue, $connection) override
  "cron": string | null,          // five-field cron expression
  "frequency": { "unit": "seconds", "every": integer } | null, // sub-minute interval; null otherwise
  "timezone": string | null,      // from ->timezone(...)
  "without_overlapping": boolean,
  "without_overlapping_expires_at": integer | null, // ->withoutOverlapping($minutes); null when no literal arg
  "on_one_server": boolean,
  "run_in_background": boolean,
  "even_in_maintenance_mode": boolean, // from ->evenInMaintenanceMode()
  "constraints": array<string>,   // opaque labels for non-cron restrictions, sorted ascending
  "file": string,                 // path to the root call (->command/->job/->call/->exec)
  "line": integer
}
```

`$defs/scheduleEntry`. All fields are required. `name`, `target`, `cron`, `frequency`, `timezone`, `queue`, `connection`, and `without_overlapping_expires_at` may be `null`.

`arguments[]` is the literal parameters array passed to `->command(Class::class, [...])` — `command` kind only; `[]` for every other kind. Plain items render as the stringified value, keyed items as `"key=value"`, booleans as `"true"`/`"false"`; unresolvable items are skipped. `queue` and `connection` are the 2nd / 3rd `->job($job, $queue, $connection)` overrides, `job` kind only, `null` when absent. `without_overlapping_expires_at` is the expiry minutes from `->withoutOverlapping($minutes)`; it is `null` when `withoutOverlapping()` carries no literal argument — the framework default of 1440 is deliberately not fabricated. The `without_overlapping` boolean is independent and unchanged.

`name` carries the schedule entry's `->name(...)` label (verbatim string) when one is declared, and is `null` otherwise. `even_in_maintenance_mode` is `true` only when `->evenInMaintenanceMode()` appears in the chain, mirroring Laravel's runtime gate that otherwise skips scheduled tasks while the app is in maintenance mode.

`kind` is determined by the chain's root call:

- `command` — `->command(string|FQCN)`. `target` is the signature string verbatim (`"mail:send"`, `"mail:send {--queue=default}"`), or the FQCN when the argument was a `::class` constant.
- `job` — `->job(new X)` or `->job(X::class)`. `target` is the FQCN.
- `closure` — `->call(...)`. `target` is `null` for inline closures, `"FQCN::method"` for tuple callables (`[Cls::class, 'method']`) and Laravel callable strings (`'App\\Cls@method'`).
- `exec` — `->exec(string)`. `target` is the shell command string.

`cron` is the canonical five-field expression for every recognised frequency helper (`daily` → `"0 0 * * *"`, `everyFiveMinutes` → `"*/5 * * * *"`, `cron('*/5 8-17 * * 1-5')` passed through verbatim). It is `null` when no recognised helper appears, when the helper's argument is a variable, or when the last frequency helper in the chain is unrecognised. The recognised helpers are listed on the [schedule scanner page](../guides/why-was-my-code-missed.md). When multiple frequency helpers chain together, last wins (mirroring Laravel's runtime).

`frequency` carries a structured sub-minute interval — `{ "unit": "seconds", "every": <N> }` — for the seven sub-minute helpers (`everySecond` → `{ "unit": "seconds", "every": 1 }`, `everyTenSeconds` → `every: 10`; also `everyTwoSeconds`, `everyFiveSeconds`, `everyFifteenSeconds`, `everyTwentySeconds`, `everyThirtySeconds`). A five-field cron expression cannot express sub-minute intervals, so these entries carry `cron: null` and the interval lives in `frequency` instead. For every other entry `frequency` is `null`. At most one of `cron` / `frequency` is non-null: when a chain mixes cron-based and sub-minute helpers, last wins (mirroring Laravel's runtime) and the loser is cleared. `unit` is backed by the `FrequencyUnit` enum, currently `"seconds"` only.

`constraints[]` carries opaque labels for non-cron restrictions: day-of-week (`"weekdays"`, `"sundays"`, …), time-window (`"between(8:00,17:00)"`, `"unlessBetween(...)"`), conditional (`"when(closure)"`, `"skip(closure)"`), and environment (`"environments(production,staging)"`). Sorted ascending. Constraints are emitted in addition to `cron` because Laravel evaluates them at runtime alongside the cron tick — they are not folded into the expression.

`file` and `line` point to the root method call of the chain (`$schedule->command(...)`), not the trailing modifier.

Entries are sorted by `(file, line)` ascending. Deduplication is on `(file, line, kind, target)`; merging across kernel / bootstrap / facade discovery favours kernel and bootstrap forms over facade.

Cross-link is one-directional: `scheduled[*].target` with `kind: "job"` carries a job FQCN that consumers can join against `jobs[*].fqcn` client-side. There is no `jobs[*].scheduled` back-pointer for rationale.

See [schedule scanner](../guides/why-was-my-code-missed.md) for behaviour details and known limitations.

## `routes[]`

Registered HTTP routes discovered from the application's route definitions. One entry per route.

```
{
  "method": enum,                 // HTTP verb, uppercase
  "uri": string,                  // route URI, enclosing group prefixes applied; leading slash, root "/"
  "name": string | null,          // group name prefix + ->name(...); null when unnamed
  "controller_fqcn": string | null,   // FQCN of the controller; null for closure / non-controller routes
  "controller_method": string | null, // controller action method; null for closure / non-controller routes
  "middleware": array<string>,    // middleware identifiers applied to the route, including those inherited from enclosing groups; verbatim names (alias->class and group expansion are not resolved)
  "file": string,                 // path to the route definition, relative to app root
  "line": integer,                // 1-indexed line of the route definition
  "dispatches": array             // events/jobs dispatched inside the route's controller method; same shape as listeners[*].dispatches
}
```

`$defs/route`. All fields are required. `name`, `controller_fqcn`, and `controller_method` may be `null`.

`dispatches[]` uses `$defs/dispatch` — the same shape as `listeners[*].dispatches` — and lists the events/jobs dispatched inside the route's controller method, cross-linked from their dispatch sites. It is populated by the cross-link pass and stays empty until cross-linked, as well as for closure routes and routes whose controller cannot be resolved.

`method` enum:

```
GET, POST, PUT, PATCH, DELETE, OPTIONS, ANY
```

A route registered against multiple verbs that share one definition is reported as `ANY`. Routes whose action is a closure (or otherwise not a `Controller@method` callable) carry `null` for both `controller_fqcn` and `controller_method`; `name` is `null` whenever no `->name(...)` was applied.

`uri` carries the route URI with the prefixes of any enclosing `Route::group(...)` prepended; it always begins with a leading slash, and the root is `/`. `name` is the enclosing group name prefix concatenated as-is (no separator inserted) with the route's own `->name(...)`. See [routes scanner](../guides/why-was-my-code-missed.md) for the group merge rules.

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

See [mailables scanner](../guides/why-was-my-code-missed.md) for discovery paths and known limitations.

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
- `false` — either `via()` resolved to a literal channel list (in which case `channels[]` is populated), or `via()` is not declared on the class at all (in which case `channels: []` — an intentional zero, not unknown). Method-level resolution across parents / traits is out of scope, so an inherited `via()` reads as "no `via()` declared".

`notified_from[]` uses `$defs/dispatchSite`. Populated by the cross-link pass from dispatch sites with finalized `kind === 'notification'` whose `target` matches the notification's FQCN. Sorted by `(file, line)`. This is the **only** section whose `$defs/dispatchSite` entries may carry the optional `channels` array — the static channel filter from `Notification::send(..., $channels)` / `sendNow(..., $channels)`, which overrides the notification's `via()` for that call. See the `channels` note under [`$defs/dispatchSite`](#events) above for value shape and omission rules.

Entries are sorted by `fqcn` ascending.

See [notifications scanner](../guides/why-was-my-code-missed.md) for discovery paths and known limitations.

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
  "routes": integer,
  "mailables": integer,
  "notifications": integer,
  "unresolved_dispatches": integer
}
```

Counts mirror the sizes of the corresponding arrays. `model_events` is intentionally not in `stats` — it's derived data, not a primary discovery output.

## Paths

Every `file` is relative to the app root, forward-slashed, and never absolute. The schema rejects a leading `/`, `\` or drive letter.

## Versioning and breaking changes

Two versions, two jobs:

- `schema_version` (`"MAJOR.MINOR"`) is the version of the document shape. **Consumers key on this.** The schema pins the major (`^1\.[0-9]+$`).
- `loom_version` is the version of the package that wrote the file. It is informational; nothing should branch on it.

Rules for `schema_version`:

| Change | Bump |
| --- | --- |
| New optional field, new enum value, new section | Minor |
| Required field made optional | Minor |
| New **required** field | Major |
| Optional field made required | Major |
| Field renamed, retyped or removed; enum value removed; section removed | Major |
| Bug fix that doesn't change shape | None |

Reader behaviour, in `IndexLoader`, `loom:check` and `loom:diff`:

- Same major, any minor: loads. Unknown keys are ignored.
- Missing `schema_version` (written before 1.0), older major or newer major: refused with a message to re-run `php artisan loom:scan`. There is no migration; the index is a derived file.
- `loom:diff` refuses to compare two indexes with different majors, and exits `2`.

`schema_version` `1.0` is the baseline. Before it, `loom_version` was the only marker and several shapes changed without a bump (for example `closure_listeners[].end_line` and `scheduled[].name` became required). Those changes are folded into `1.0`; from here the table above applies strictly.

Because the schema sets `additionalProperties: false`, a consumer that validates with a stored copy of the schema will reject a later minor. Validate with the schema shipped in the same release as the producer, or don't validate.

## Open enums

Some enums grow in minor releases. Consumers must tolerate values they don't know and must not fail on them:

- `kind` on dispatch entries (`event`, `job` today)
- `registration` on listeners and observers
- `reason` on `unresolved_dispatches[]`
- `confidence`: only `high` is emitted today; `medium` and `low` are reserved, ordered `high > medium > low`
- `routes[].method`

Removing or renaming a value is a major change. Adding one is minor.

What to do about it:

- Pin the Loom version you build tooling against, and upgrade deliberately.
- Read the [changelog](https://github.com/lucasp1337/laravel-loom/blob/main/CHANGELOG.md) before upgrading; shape changes are listed there.
- Regenerate the index with `php artisan loom:scan` after every upgrade instead of reusing an old file. See [Upgrading](../upgrading.md).
