# Scanner internals

Contributor reference for how each scanner discovers, parses and cross-links its primitive. For what consumers can and cannot expect, see [What Loom detects](../reference/what-loom-detects.md) and [Why was my code missed](../guides/why-was-my-code-missed.md). For the pipeline as a whole see [architecture](architecture.md).

Sections are per scanner. Known limitations and triage checklists live in the consumer pages above.

## EventScanner

Discovers event classes and emits the `events[]` section of the index.

### What it detects

EventScanner uses two discovery paths and merges them by FQCN:

1. **Filesystem walk of `app/Events/`.** Every top-level `class` declaration with a non-null `namespacedName` becomes an event entry. Abstract classes are included. Interfaces and traits are skipped. Anonymous classes are skipped (they have no FQCN).

2. **Dispatch-site seeding across `app/`.** EventScanner walks every PHP file under `app/` and records statically resolvable targets from:
   - `event(new SomeEvent(...))` and `event(SomeEvent::class)` (`form: helper`). The first argument is extracted even with trailing args, so `event(new SomeEvent($payload), $extraArgs)` seeds `SomeEvent`. Plain-old-PHP-object events (no `Dispatchable` trait) are discovered this way.
   - `broadcast(new SomeEvent(...))` and `broadcast(SomeEvent::class)` (`form: helper`). The bare broadcast form seeds discovery the same way `event(...)` does, so broadcast-only events reach `events[]`. (The conditional `broadcast_if`/`broadcast_unless` forms are emitted as dispatch sites by DispatchScanner but are not used for discovery seeding here.)
   - `Event::dispatch(new SomeEvent(...))` and `Event::dispatch(SomeEvent::class)` (`form: facade`)
   - `SomeEvent::dispatch(...)`, `SomeEvent::dispatchIf($cond, ...)`, and `SomeEvent::dispatchUnless($cond, ...)` (`form: dispatchable`). The conditional forms resolve to the same target as `dispatch(...)`; the leading condition argument is ignored for resolution.

   When a seeded target's FQCN isn't already known from the filesystem walk, EventScanner locates the class file via a PSR-4 guess (mapping leading `App\` to `app/`). The class must exist on disk and contain the declared FQCN; otherwise the candidate is dropped.

   The `dispatchable` form is subject to an extra filter: candidates are only accepted if their resolved file sits under `app/Events/`. Without this filter, every job class using the `Dispatchable` trait would land in `events[]`. The `helper` and `facade` forms have no such filter — they are unambiguous event dispatches per Laravel's API.

### Output

One entry per discovered event FQCN, conforming to `$defs/event`:

```json
{
  "id": "App\\Events\\OrderPlaced",
  "fqcn": "App\\Events\\OrderPlaced",
  "kind": "class",
  "file": "app/Events/OrderPlaced.php",
  "line": 12,
  "dispatched_from": [],
  "handled_by": []
}
```

`dispatched_from` and `handled_by` are emitted as empty arrays. They are populated by the cross-link pass in `IndexBuilder` using DispatchScanner and ListenerScanner output. EventScanner never writes to those fields directly.

Entries are sorted by `fqcn` ascending. `id` always equals `fqcn` (the schema reserves `id` for future kinds; `kind` is always `"class"`).

### Expected behavior

- **Event class outside `app/Events/`.** Picked up when dispatched via the helper or facade form. Filesystem walk's `file`/`line` wins over the dispatch-site discovery (the class declaration is more authoritative than the call site).
- **Multiple classes in one file.** Each top-level `Stmt\Class_` produces its own entry. This is a PSR-4 violation but legal PHP, and the scanner handles it.
- **Abstract base event classes.** Included. They have a file and line and may be referenced by subclasses.
- **Trait usage on event classes.** No special handling. The class itself is recorded; the trait is irrelevant.
- **Dispatch site uses `\Fully\Qualified\Name`.** Resolved correctly via NameResolver — fully qualified names need no `use` statement.
- **Dispatch site inside a closure.** The dispatch-site visitor reads on `leaveNode`, so closures are walked. Their resolved targets do contribute to event discovery (they're real references to event classes), but DispatchScanner skips emitting `dispatched_from` for sites inside closures. See [dispatches.md](#dispatchscanner).
- **Same event discovered via multiple paths.** Deduped by FQCN. Filesystem walk wins for `file`/`line`.
- **Parse errors in a file.** `AstWalker` swallows them. The scanner sees no hits from that file.


## ListenerScanner

Discovers event listeners and emits the `listeners[]` section of the index.

> Closure-based listeners (arrow functions and `function () { … }` values in `$listen`, `Event::listen()`, or subscriber return-arrays) are emitted into the separate `closure_listeners[]` section instead — they have no FQCN to key against the `listeners[]` shape. See [closure-listeners.md](#closure-listeners).

### What it detects

ListenerScanner uses four discovery paths and merges them by listener FQCN:

1. **Auto-discovery via `app/Listeners/`.** Every class in `app/Listeners/` with a public `handle()` method is a listener candidate. The first parameter's type-hint becomes the event the listener handles. Classes that transitively implement `Illuminate\Contracts\Queue\ShouldQueue` — directly or via a parent class indexed under `app/` — are marked `queued: true`.

2. **`$listen` array on `EventServiceProvider`.** Walks the entire `app/` tree (not just `app/Providers/`) and looks at classes named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. The `$listen` property (any visibility, must be `array`) is parsed: each `EventClass::class => [Listener::class, …]` pair becomes a registration. Bare `Listener::class` values map to `method: "handle"`. Tuple values `[Listener::class, 'method']` preserve the method name. Resolvable callable values — `Closure::fromCallable([Listener::class, 'method'])`, `Closure::fromCallable([Listener::class])` (method defaults to `"handle"`), and `Listener::method(...)` first-class callable syntax — resolve to the same FQCN+method and route through the same merge as the literal tuple.

3. **`listen()` calls on the event dispatcher.** Walks the entire `app/` tree for `listen(EventClass::class, Listener::class)` calls made against the event dispatcher — both the `\Illuminate\Support\Facades\Event::listen(...)` facade form and the container-resolved forms (see [Container-form registrations](#container-form-registrations) below). The first argument may be a single event class-string OR an array of class-string events: `Event::listen([Event1::class, Event2::class], $listener)` binds the one listener to every event in the array and expands to one registration per event (each carrying the same listener FQCN+method). Bare `Listener::class` second arguments map to `method: "handle"`; tuple form `[Listener::class, 'method']` preserves the method name. Resolvable callable second arguments — `Closure::fromCallable([Listener::class, 'method'])`, `Closure::fromCallable([Listener::class])`, and `Listener::method(...)` first-class callable syntax — resolve identically and route through the same merge. The class-shape filter only applies in path 2 — these calls are scanner-agnostic about the surrounding class, so providers in DDD-style layouts (e.g. `app/Domain/Invoicing/Providers/InvoicingServiceProvider.php`) are discovered. All forms emit `registration: event_listen_call`.

4. **Subscribers.** Classes registered via the `$subscribe = [SubscriberClass::class, …]` array on an `EventServiceProvider`, or via `Event::subscribe(SubscriberClass::class)` calls. The subscriber's own `subscribe($events): array` method is then parsed in two complementary ways — see [Subscribers](#subscribers) below. Subscribers receive `registration: "subscriber"` — the highest-precedence source.

### Subscribers

A Laravel event subscriber can wire its handlers in two forms inside its `subscribe()` method, and Loom parses both. A single subscriber can mix them — the contributions from each form are merged.

**Return-array form.** `return [Event::class => 'method', Event::class => [self::class, 'method']]`. Each pair contributes a `{event, method}` entry to the subscriber's own `handles[]`. Both bare-string method values and tuple values `[self::class, 'method']` / `[static::class, 'method']` / `[OwnFqcn::class, 'method']` resolve to the subscriber itself.

**Imperative form.** A body that calls `$events->listen(...)` against the dispatcher parameter. The dispatcher is identified by parameter position — its name and type-hint are irrelevant. The visitor walks into control-flow constructs (`if`, `foreach`, `try/catch`, …) but does not descend into nested closures or other method bodies.

Routing rules for an imperative `$events->listen(EventClass::class, $callable)` call:

| Callable shape | Routes to |
|---|---|
| `[self::class, 'method']`, `[static::class, 'method']`, or `[OwnFqcn::class, 'method']` | Subscriber's own `handles[]` (own FQCN) |
| Bare string `'method'` (Laravel binds bare-string callables to the subscriber instance) | Subscriber's own `handles[]` |
| `[OtherClass::class, 'method']` | Registers `OtherClass` as a regular `listeners[]` entry with `registration: "subscriber"` |
| `fn ($e) => …` or `function ($e) { … }` | Emitted into `closure_listeners[]` with `registration: "subscriber"` |

The third rule has a noteworthy consequence: when a subscriber imperatively wires a *foreign* listener (one not declared on the subscriber's own class), that subscriber becomes the registration-source for the foreign listener — its `registration` is upgraded to `subscriber`, the highest-precedence source. This matches Laravel's runtime semantics (the subscriber is responsible for the registration) but means a listener's `registration` can flip from a lower-precedence value to `subscriber` purely because some subscriber elsewhere chose to wire it.

The precedence rule (`subscriber > listen_array > event_listen_call > auto_discovered`) is unchanged; only what counts as a `subscriber`-sourced registration is broader.

### Container-form registrations

Beyond the `Event::` facade, a `listen()` call against an event dispatcher resolved from the container is recognized. These route through the same path-3 merge and emit `registration: event_listen_call` — there is no separate registration source and no schema change.

The dispatcher is identified by FQCN: `Illuminate\Contracts\Events\Dispatcher`, `Illuminate\Events\Dispatcher`, or the bare `Dispatcher` basename. The recognized receiver shapes are:

| Receiver | Example |
|---|---|
| App container array-access (literal `'events'` key) | `$this->app['events']->listen($event, $listener)` |
| `app()` helper with a Dispatcher FQCN | `app(\Illuminate\Contracts\Events\Dispatcher::class)->listen(...)` |
| `resolve()` helper with a Dispatcher FQCN | `resolve(Dispatcher::class)->listen(...)` |
| `$this->app->make(...)` / `->makeWith(...)` with a Dispatcher FQCN | `$this->app->make(Dispatcher::class)->listen(...)` |
| A local variable assigned from any of the above earlier in the same scope | `$dispatcher = app(Dispatcher::class); … $dispatcher->listen(...)` |

Every listener form supported on the facade is supported here too: `Listener::class`, the tuple `[Foo::class, 'method']`, `Closure::fromCallable([...])`, the first-class `Foo::method(...)` form, and inline closures (which route to `closure_listeners[]` — see [closure-listeners.md](#closure-listeners)).

Variable tracking is a single flat pass within the file (assignment-precedes-use): the variable must be assigned from a recognized dispatcher expression before the `listen()` call. Reassigning the variable to a non-dispatcher value invalidates tracking from that point.

### Output

One entry per listener FQCN, conforming to `$defs/listener`:

```json
{
  "fqcn": "App\\Listeners\\SendOrderConfirmation",
  "file": "app/Listeners/SendOrderConfirmation.php",
  "line": 14,
  "handles": [
    { "event": "App\\Events\\OrderPlaced", "method": "handle" }
  ],
  "registration": "listen_array",
  "queued": true,
  "dispatches": []
}
```

`dispatches` is always emitted as an empty array. It is populated by the cross-link pass from DispatchScanner's per-call-site data.

`registration` is set per the precedence rule: `subscriber > listen_array > event_listen_call > auto_discovered`. When a listener is discovered through multiple paths, the entry's `registration` reports the highest-precedence source observed.

`handles[]` is a list of `{event, method}` pairs. Both fields are always present; `method` defaults to `"handle"` when the registration didn't name one (auto-discovery, bare `Listener::class` in `$listen`, bare `Listener::class` in `Event::listen()`). Entries are deduped by the `(event, method)` tuple and sorted by `event` ascending then `method` ascending. A single listener can have multiple `handles[]` entries for the same event under different methods.

Entries are sorted by `fqcn` ascending.

### Multi-handler listeners

A single listener class can declare multiple handler methods and be registered against different events under different methods. Loom represents each registration as its own `handles[]` entry:

```php
// EventServiceProvider
protected $listen = [
    OrderPlaced::class => [
        [SendNotifications::class, 'handleOrderPlaced'],
    ],
    OrderRefunded::class => [
        [SendNotifications::class, 'handleOrderRefunded'],
    ],
];
```

emits a single `listeners[]` entry:

```json
{
  "fqcn": "App\\Listeners\\SendNotifications",
  "handles": [
    { "event": "App\\Events\\OrderPlaced", "method": "handleOrderPlaced" },
    { "event": "App\\Events\\OrderRefunded", "method": "handleOrderRefunded" }
  ],
  "registration": "listen_array"
}
```

The same event handled by different methods on one listener (`[Listener::class, 'foo']` and `[Listener::class, 'bar']` against the same event) produces two `handles[]` entries — the dedupe key is the full `(event, method)` tuple.

### Expected behavior

- **Listener registered via multiple paths.** Single entry. `handles` is the union of `(event, method)` tuples; `registration` is the highest-precedence source.
- **Listener with typed `handle(OrderPlaced $event)`.** Auto-discovered. `handles: [{ "event": "App\\Events\\OrderPlaced", "method": "handle" }]` (or whatever the resolved type-hint is).
- **Listener with `handle($event)` (no type-hint).** Auto-discovered with `handles: []`. The listener is still registered; it just doesn't auto-discover a target event. Other paths may still add entries.
- **Listener listed in `$listen` but located outside `app/Listeners/`.** Picked up via the PSR-4 guess (leading `App\` → `app/`). If the file can't be located on disk, the listener is dropped (the schema requires `file` and `line`).
- **`$listen` tuple form `[Listener::class, 'method']`.** Both the listener FQCN and the method name are recorded. The resulting `handles[]` entry is `{event: …, method: "method"}`.
- **`Event::listen(Event::class, [Listener::class, 'method'])`.** Method name preserved. Bare `Event::listen(Event::class, Listener::class)` maps to `method: "handle"`.
- **`Event::listen([Event1::class, Event2::class], Listener::class)`.** The array of class-string events expands to one registration per event — the listener's `handles[]` gains an entry for each event in the array.
- **Resolvable callable forms.** `Closure::fromCallable([Listener::class, 'method'])` and `Listener::method(...)` first-class callable syntax both resolve to a regular `listeners[]` entry for `Listener::method`. The single-element `Closure::fromCallable([Listener::class])` mirrors bare `::class` and maps to `method: "handle"`. Valid in both the `$listen` array (`listen_array`) and `Event::listen()` (`event_listen_call`); the resolved FQCN+method flows through the same merge as the literal `[Listener::class, 'method']` tuple.
- **`Event::listen()` inside a non-provider class.** Discovered. The `Event::listen` visitor doesn't filter by surrounding class shape — it accepts any static call.
- **`Event::listen(\Illuminate\Support\Facades\Event::class, ...)` fully qualified.** Resolved correctly via NameResolver.
- **`ShouldQueue` implemented transitively.** `queued: true` whenever any class in the listener's `extends` chain (or the listener itself) carries `implements ShouldQueue`. Resolution uses the cross-file [class hierarchy resolver](class-hierarchy.md).


## Closure Listeners

Discovers closure-based event listener registrations and emits the `closure_listeners[]` section of the index.

Closures and arrow functions cannot be represented in `listeners[]` because they have no FQCN. They get their own top-level section, with one entry per closure registration site.

`closure_listeners[]` is for genuinely anonymous, unresolvable closures. A callable that resolves to a concrete class and method — including `Closure::fromCallable([Foo::class, 'method'])` and the `Foo::method(...)` first-class callable form — is a *regular* listener and lands in `listeners[]`, not here. See [listeners.md](#listenerscanner).

### What it detects

Three discovery paths:

1. **Closure value inside `$listen` array.** `protected $listen = [OrderPlaced::class => [fn ($e) => …]]` (or a long-form `function ($e) { … }`) on a class named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. Emitted with `registration: "listen_array"`.

2. **Closure as the second argument of a dispatcher `listen()` call.** `Event::listen(OrderPlaced::class, fn ($e) => …)` anywhere under `app/`. Emitted with `registration: "event_listen_call"`. The class-shape filter that applies to `$listen` walks does NOT apply here — any qualifying `listen()` call does. This covers both the `Event::` facade form and the container-resolved dispatcher forms (`$this->app['events']->listen(...)`, `app(Dispatcher::class)->listen(...)`, `resolve(Dispatcher::class)->listen(...)`, `$this->app->make(Dispatcher::class)->listen(...)`, and a local variable assigned from one of those) — see the [ListenerScanner container-form registrations](#container-form-registrations) for the exact receiver shapes and their limitations.

3. **Closure inside a subscriber's `subscribe()` body** — either as a return-array value (`return [OrderPlaced::class => fn ($e) => …]`) or as the second argument to an imperative `$events->listen(OrderPlaced::class, fn ($e) => …)` call against the dispatcher parameter. Applies to any class registered as a subscriber (via `$subscribe` array or `Event::subscribe(...)`). Both sub-cases emit with `registration: "subscriber"`.

Both `Closure` (long-form `function ($e) { … }`) and `ArrowFunction` (`fn ($e) => …`) are detected. The event key may be a `::class` reference or a raw string (`'user.created'`).

### Output

One entry per closure registration site, conforming to `$defs/closureListener`:

```json
{
  "event": "App\\Events\\OrderPlaced",
  "file": "app/Providers/EventServiceProvider.php",
  "line": 38,
  "end_line": 41,
  "registration": "event_listen_call",
  "queued": false,
  "dispatches": [
    {
      "target": "App\\Events\\OrderConfirmationSent",
      "kind": "event",
      "confidence": "high",
      "file": "app/Providers/EventServiceProvider.php",
      "line": 40
    }
  ]
}
```

- `event` is always a `string`. FQCN for `::class` registrations, raw string for `'user.created'`-style registrations.
- `file` / `line` point to the closure node itself, not the surrounding registration call. `line` is the closure's opening line.
- `end_line` is the closure body's closing line. Together `[line, end_line]` is the closure's source span — the span used to attribute dispatch sites (see [`dispatches`](#dispatches)).
- `registration` enum: `listen_array`, `event_listen_call`, `subscriber`.
- `queued` is always `false`. Closure-queue detection is out of scope.
- `dispatches` carries the events and jobs dispatched inside the closure body. Empty when the body dispatches nothing. See below.

### `dispatches`

Each entry in `dispatches[]` is a dispatch object conforming to `$defs/dispatch` — the same shape used by `listeners[*].dispatches`:

```json
{ "target": "App\\Events\\OrderConfirmationSent", "kind": "event", "confidence": "high", "file": "...", "line": 40 }
```

- `target` — FQCN of the dispatched event or job.
- `kind` — `event` or `job`.
- `confidence` — `high` / `medium` / `low`.
- `file` / `line` — the dispatch site.

Attribution is **positional, by source span.** A closure listener has no class or method identity to key on — unlike `listeners[*].dispatches`, which matches a dispatch to its enclosing listener by class plus method. So closures match by line instead: a dispatch site is attributed to a closure listener when it sits in the same file and its line falls within `[line, end_line]` inclusive.

Only resolved (statically-known) dispatches are captured. A dispatch with a dynamic or otherwise unresolvable target inside a closure body is not added to `dispatches[]` — and is not added to `unresolved_dispatches[]` either; closure-internal unresolved dispatches are out of scope.

Entries are sorted by `(event, file, line)` ascending for determinism.

### Expected behavior

- **FQCN event key.** `Event::listen(OrderPlaced::class, fn ($e) => …)` → `event: "App\\Events\\OrderPlaced"`.
- **String event key.** `Event::listen('user.created', fn ($e) => …)` → `event: "user.created"`.
- **Arrow function vs long-form closure.** Both forms are detected and produce identical output. The `event`, `file`, and `line` are recorded against the closure node.
- **Mixed `$listen` arrays.** When `$listen = [OrderPlaced::class => [SendNotifications::class, fn ($e) => …]]`, the class entry flows into `listeners[]` and the closure entry flows into `closure_listeners[]`. Each path emits independently; nothing is dropped.
- **Subscriber return-arrays with mixed values.** `return [OrderPlaced::class => 'handlerMethod', OrderRefunded::class => fn ($e) => …]` contributes the string method to the subscriber's `listeners[*].handles[]` and the closure to `closure_listeners[]`.
- **Multiple closure registrations against the same event.** Each registration site is its own entry. No dedupe by `event`.
- **Dispatches inside the closure body.** A resolved `event(new Foo)` / `dispatch(new Bar)` inside the closure populates that entry's `dispatches[]`. An empty (or dispatch-free) closure keeps `dispatches: []`.
- **No reverse edge.** A dispatch made inside a closure listener appears in that closure's `dispatches[]`, but the target event's or job's reverse `dispatched_from[]` does **not** list the closure as a source. This mirrors the `events[*].handled_by` design: closures have no nameable identity to record on the other side of the edge. Consumers asking "where is `Foo` dispatched from?" won't see closure listeners in `dispatched_from`.
- **Nested closures.** A dispatch sitting inside a closure nested within the listener closure is attributed to every enclosing closure-listener span that contains it. Overlapping spans only arise through nesting.


## ObserverScanner

Discovers Eloquent observers and emits both `observers[]` and `model_events[]` sections of the index.

This is the only scanner that emits to two top-level schema sections. Both come from the same discovery walk; the dual emission is bundled here because the data is the same.

### What it detects

ObserverScanner uses three discovery paths and emits both observer entries and synthetic model-event entries:

1. **`#[ObservedBy(Observer::class)]` attribute on models.** Walks `app/`, finds top-level classes carrying an attribute whose resolved name is `Illuminate\Database\Eloquent\Attributes\ObservedBy`. The class carrying the attribute is the model; the attribute's `::class` arguments are the observer(s). The array form `#[ObservedBy([A::class, B::class])]` produces multiple registrations.

2. **`Model::observe(Observer::class)` static calls.** Walks `app/`, matches `Expr\StaticCall` with method name `observe`. The receiver determines the model:
   - `User::observe(...)` — model is the resolved FQCN on the left
   - `static::observe(...)` / `self::observe(...)` inside a class body — model is the enclosing class FQCN
   - `parent::observe(...)` — skipped
   - `$this->observe(...)` — skipped (not a static call)

   The argument can be `Observer::class`, or an array of `::class` references for multiple observers.

3. **`Event::listen('eloquent.{hook}: {Model}', $handler)` listener strings.** Walks `app/`, matches `Event::listen()` calls with a literal first-arg string of the form `eloquent.{hook}: {ModelFQCN}` (with or without the space after the colon). The hook must be one of the canonical Eloquent hooks. The handler can be:
   - `'Class@method'` string
   - `[Observer::class, 'method']` array
   - `Observer::class` with no method (defaults to the hook name)
   - Closures and dynamic args are skipped

   Path 3 contributes to **`model_events[]` only**, never to `observers[]`. The handler may not be a true observer class — promoting it to `observers[]` with a synthetic single-hook `hooks` list would misrepresent it.

For each observer discovered through paths 1 or 2, the scanner enumerates hook methods. It locates the observer's file (from the in-memory class-to-file map built during the walk, with PSR-4 fallback) and collects every method named with one of the canonical Eloquent hooks. Visibility is not a filter — `public`, `protected`, and `private` methods all count.

### Output

#### `observers[]` entries (`$defs/observer`)

```json
{
  "fqcn": "App\\Observers\\UserObserver",
  "file": "app/Observers/UserObserver.php",
  "line": 9,
  "observes": "App\\Models\\User",
  "registration": "attribute",
  "hooks": ["created", "deleted", "updated"],
  "dispatches": []
}
```

One entry per `(observerFqcn, modelFqcn)` pair. An observer registered against multiple models produces multiple entries.

`registration` precedence when the same pair is found via both paths: `attribute > observe_call`. The attribute is the modern Laravel 11+ pattern declared directly on the model — when both exist, it wins.

`hooks` is sorted ascending. `dispatches` is always emitted as an empty array (populated by the cross-link pass from DispatchScanner).

#### `model_events[]` entries (`$defs/modelEvent`)

```json
{
  "id": "eloquent.created: App\\Models\\User",
  "kind": "model_event",
  "model": "App\\Models\\User",
  "event": "created",
  "handled_by": ["App\\Observers\\UserObserver::created"]
}
```

One entry per `(model, hook)` pair. `handled_by` aggregates:

- Observer-hook references — every observer that observes `model` and has `hook` in its `hooks` list contributes `"ObserverFqcn::hook"`
- Path 3 handlers — every `Event::listen('eloquent.{hook}: {model}', ...)` registration contributes `"HandlerFqcn::method"`

`handled_by` is deduped and sorted. If a `(model, hook)` pair has no observer hook method and no path-C handler, no `model_events` entry is emitted — the section catalogs actual handlers, not every possible model event.

Entries are sorted by `id` ascending.

#### Canonical hook enum

The hook names recognized in both `hooks[]` (observer methods) and the `model_events.event` field:

```
retrieved, creating, created, updating, updated, saving, saved,
deleting, deleted, restoring, restored, replicating, trashed,
forceDeleting, forceDeleted, booting, booted
```

### Expected behavior

- **Observer registered via both attribute and `Model::observe()`.** Single entry per `(observer, model)` pair with `registration: attribute`.
- **`static::observe(UserObserver::class)` inside `User::booted()`.** Model resolves to `User` via the enclosing class context.
- **Observer registered against multiple models.** Multiple entries (one per model). The schema's single-string `observes` constraint requires this.
- **Observer with no hook methods that match the canonical enum.** Still emitted with `hooks: []`. The registration exists; the hook list is empty.
- **Observer class with mixed-visibility hook methods.** All hooks are collected regardless of visibility (public, protected, private).
- **`#[ObservedBy([A::class, B::class])]`.** Produces two observer entries.
- **Model_events dedupe.** Observer hooks AND `Event::listen('eloquent.created: User', UserObserver::class . '@created')` referring to the same observer hook contribute the same `"UserObserver::created"` string — deduplicated to a single entry in `handled_by`.
- **Path 3 with non-observer handler.** `Event::listen('eloquent.deleted: App\Models\Product', 'App\Handlers\InvoiceHandler@deleted')` produces a `model_events` entry with `InvoiceHandler::deleted` in `handled_by` but does NOT create an observer entry for `InvoiceHandler`.


## JobsScanner

Discovers queueable and synchronous job classes and emits the `jobs[]` section of the index.

### What it detects

JobsScanner finds job classes via two discovery paths and merges them by FQCN:

1. **Filesystem walk of `app/Jobs/`.** Every `*.php` file under `app/Jobs/` (recursively) is parsed and any concrete class found becomes a job candidate. Abstract classes, interfaces, traits, and anonymous classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by `Bus::dispatch(...)`, `dispatch(...)`, or a Dispatchable form — `X::dispatch()`, `X::dispatchIf($cond, ...)`, `X::dispatchUnless($cond, ...)` — is located via the PSR-4 guess (leading `App\` → `app/`) and parsed. The conditional forms resolve to the same target as `X::dispatch()`; the leading condition argument does not affect resolution. This lets jobs in DDD-style layouts like `app/Domain/Billing/Jobs/SettleInvoice.php` get picked up even though they live outside `app/Jobs/`. A target wrapped in a fluent chain is resolved through the chain to its FQCN: `dispatch((new ProcessOrder())->delay(60))` and `ProcessOrder::dispatch()->onQueue('high')` both seed `ProcessOrder` (only `new X` / `X::class` receivers resolve, not variable receivers). The dispatch-time modifiers on that chain are captured into the site's `overrides` object — see Cross-link behavior.

Entries are deduped by FQCN. A job discovered through both paths produces a single entry.

`queued: true` iff the class transitively implements `Illuminate\Contracts\Queue\ShouldQueue` — either declared on its own `implements` clause, or inherited via a parent class indexed under `app/`. Resolved via the cross-file [class hierarchy resolver](class-hierarchy.md). Inheritance through vendor or framework classes that live outside `app/` is opaque to the resolver and won't surface as `queued: true`.

### Output

One entry per job FQCN, conforming to `$defs/job`:

```json
{
  "fqcn": "App\\Jobs\\ProcessOrder",
  "file": "app/Jobs/ProcessOrder.php",
  "line": 14,
  "queued": true,
  "queue_config": {
    "connection": "redis",
    "queue": "high",
    "delay": null,
    "tries": 3,
    "timeout": 60,
    "backoff": null
  },
  "dispatched_from": [],
  "dispatches": []
}
```

`queue_config` is `null` when `queued: false`. When `queued: true` it is an object with all six keys present — `connection`, `queue`, `delay`, `tries`, `timeout`, `backoff`. Each value is either the scalar literal declared as a class property, or `null` when the property is not declared on the class. `null` means "not declared" — at runtime Laravel applies its own defaults from `config/queue.php` and from the framework's job traits.

`dispatched_from` and `dispatches` are always emitted as empty arrays from the scanner. Both are populated by the cross-link pass from DispatchScanner's per-call-site data.

Entries are sorted by `fqcn` ascending.

### Cross-link behavior

- **`jobs[*].dispatched_from`** — for each dispatch site with finalized `kind === 'job'` whose `target` matches a job FQCN, a `$defs/dispatchSite` entry is appended. Sites carrying the pre-disambiguation `kind: ambiguous` do NOT contribute; they're finalized in cross-link Phase 2 before this join runs.

  When the dispatch site carries dispatch-time modifiers, the entry also carries an optional `overrides` object. For jobs both the inner argument-instance chain (`dispatch((new ProcessOrder)->onQueue('high')->delay(60))`) and the outer PendingDispatch chain (`ProcessOrder::dispatch($o)->onQueue('high')->onConnection('redis')->delay(60)`, `dispatch(new ProcessOrder)->afterCommit()`) are read. Source mapping: `->onQueue('high')` → `queue`, `->onConnection('redis')` → `connection`, `->delay(60)` → `delay` (integer seconds), `->afterCommit()` → `after_commit`, `->locale(...)` → `locale`, `->mailer(...)` → `mailer`. The key is omitted when no static modifier is present:

  ```json
  {
    "file": "app/Services/Checkout.php",
    "line": 91,
    "method": "App\\Services\\Checkout::finalize",
    "overrides": { "connection": "redis", "queue": "high", "delay": 60 }
  }
  ```

  `overrides` records what the call site changed; `queue_config` still reflects the job's class-default property declarations. The two are independent.

- **`jobs[*].dispatches`** — for each dispatch site whose enclosing class FQCN matches a job and whose enclosing method is `handle`, a `$defs/dispatch` entry is appended. Dispatches emitted from helper methods called by `handle()` are NOT attributed (see the [troubleshooting guide](../guides/why-was-my-code-missed.md)).


## DispatchScanner

Identifies dispatch sites (event and job dispatches) throughout the codebase, emits `unresolved_dispatches[]` directly, and feeds the cross-link pass that populates `listeners[*].dispatches`, `observers[*].dispatches`, and `events[*].dispatched_from`.

This is the most cross-cutting scanner. It runs last (after EventScanner, ListenerScanner, and ObserverScanner) so the cross-link pass has access to every primitive's data.

### What it detects

DispatchScanner walks every PHP file under `app/` and records dispatch sites in any class method body. Recognized forms:

- `event(new SomeEvent(...))` and `event(SomeEvent::class)` — `kind: event`, `form: helper`
- `broadcast(new SomeEvent(...))` and `broadcast(SomeEvent::class)` — `kind: event`, `form: helper` (the broadcast-path twin of `event()`; event at arg 0)
- `broadcast_if($cond, new SomeEvent(...))` and `broadcast_unless($cond, SomeEvent::class)` — `kind: event`, `form: helper`. The event is arg 1; the leading condition does not affect resolution. A dynamic event arg (`broadcast($var)`) emits an `unresolved_dispatches[]` entry like any other helper form.
- `Event::dispatch(new SomeEvent(...))` and `Event::dispatch(SomeEvent::class)` — `kind: event`, `form: facade`
- `dispatch(new SomeJob(...))` and `dispatch(SomeJob::class)` — `kind: job`, `form: job_helper`
- `Bus::dispatch(new SomeJob(...))` — `kind: job`, `form: bus_facade`
- `SomeClass::dispatch(...)` Dispatchable trait — `kind: ambiguous` at the visitor level, finalized by the cross-link pass against `events[]`
- `SomeClass::dispatchIf($cond, ...)` and `SomeClass::dispatchUnless($cond, ...)` conditional Dispatchable forms — resolved exactly like `SomeClass::dispatch(...)` (the static class is the target; the leading condition argument does not affect resolution). Same `kind: ambiguous` → cross-link finalization. These exist only as static Dispatchable-trait forms; there is no `Event::dispatchIf` / `Event::dispatchUnless` facade form (Laravel's `Event` facade has no such methods), so no facade conditional form is recognised.

A dispatched target wrapped in a fluent chain resolves through the chain to its target FQCN. `AstHelpers::resolveStaticClass()` unwraps a leading `MethodCall` chain before resolving, so `dispatch((new ProcessOrder())->delay(60))` and `ProcessOrder::dispatch()->onQueue('high')` resolve to `ProcessOrder` rather than falling through to `unresolved_dispatches[]`. Only `new X` and `X::class` receivers resolve through the chain — a variable receiver (`$job->onQueue('high')` where `$job` is a variable) does not. The chain modifier *values* are captured into the site's `overrides` object (see below).

The visitor maintains a class+method stack on `enterNode`/`leaveNode` of `Stmt\Class_` and `Stmt\ClassMethod`, plus a closure-depth counter. Each recorded site carries:

- `target` — resolved FQCN of the dispatched event or job
- `kind` — `event`, `job`, or `ambiguous` (finalized in cross-link)
- `form` — `helper`, `facade`, `dispatchable`, `job_helper`, `bus_facade`
- `class` — enclosing class FQCN
- `method` — enclosing method name
- `file`, `line` — set by the scanner from the file path being walked

When the dispatched target can't be resolved statically, the visitor emits to a separate unresolved list instead. Resolution reasons:

- **`dynamic_class_name`** — first arg is a variable (`event($var)`)
- **`string_concatenation`** — first arg is a string concat or interpolation (`event("App\\Events\\" . $x)`, `event("App\\Events\\{$x}")`)
- **`container_resolution`** — first arg is a container call (`event(app(EventKey::class))`, `event(resolve(...))`, `event($container->make(...))`)
- **`conditional_dispatch`** — first arg is a ternary whose branches can't be resolved. If both ternary branches resolve to concrete `new X()` calls, two resolved sites are emitted instead.

### Output

#### Direct: `unresolved_dispatches[]` (`$defs/unresolvedDispatch`)

```json
{
  "file": "app/Services/Notifier.php",
  "line": 42,
  "expression": "event($eventClass)",
  "reason": "dynamic_class_name"
}
```

Sorted by `(file, line)` for determinism.

#### Internal: `_dispatch_sites[]`

DispatchScanner returns an internal `_dispatch_sites` section (underscore-prefixed). `IndexBuilder` consumes it during cross-link and strips it before schema validation — the JSON output never contains this key.

Each entry carries the visitor's output plus the relative file path:

```php
[
  'target' => 'App\\Events\\OrderConfirmationSent',
  'provisionalKind' => 'event',            // 'event'|'job'|'ambiguous' from the visitor
  'form' => 'helper',
  'classFqcn' => 'App\\Listeners\\SendOrderConfirmation',
  'method' => 'handle',
  'file' => 'app/Listeners/SendOrderConfirmation.php',
  'line' => 31,
  'confidence' => 'high',
]
```

The cross-link pass consumes this to populate the three cross-linked fields below.

#### Cross-link populates these from `_dispatch_sites`

**`listeners[*].dispatches`** — entries whose `classFqcn` matches a listener AND whose enclosing method is in that listener's `handles[*].method` set. Built as `$defs/dispatch`:

```json
{
  "target": "App\\Events\\OrderConfirmationSent",
  "kind": "event",
  "confidence": "high",
  "file": "app/Listeners/SendOrderConfirmation.php",
  "line": 31
}
```

**`observers[*].dispatches`** — same shape, matched on `classFqcn === observer.fqcn` AND `method` is in the canonical Eloquent hook enum.

**`events[*].dispatched_from`** — sites with finalized `kind: event` and `target` matching an event entry. Shape per `$defs/event.dispatched_from`:

```json
{
  "file": "app/Services/Checkout.php",
  "line": 87,
  "method": "App\\Services\\Checkout::finalize"
}
```

The same `$defs/dispatchSite` shape backs `jobs[*].dispatched_from`, `mailables[*].sent_from`, and `notifications[*].notified_from`.

### Dispatch-time modifiers (`overrides`)

Each dispatch site optionally carries an `overrides` object (`$defs/dispatchOverrides`) recording statically-resolvable fluent modifiers applied at the call site. The key is emitted only when at least one modifier is found; a site with none has no `overrides` key. Recognised modifiers and their keys:

| Source | Key | Type |
|---|---|---|
| `->locale('es')` | `locale` | string |
| `->mailer('ses')` | `mailer` | string |
| `->onConnection('redis')` | `connection` | string |
| `->onQueue('high')` | `queue` | string |
| `->delay(60)` | `delay` | integer (seconds) |
| `->afterCommit()` | `after_commit` | boolean (only ever `true`) |

Keys are emitted in schema order (`locale`, `mailer`, `connection`, `queue`, `delay`, `after_commit`); only keys for modifiers actually present appear.

Two chain positions are read:

- **Inner argument-instance chain** — modifiers on the dispatched instance itself: `dispatch((new ProcessOrder)->onQueue('high')->delay(60))`, `$user->notify((new InvoicePaid)->onQueue('emails'))`, `Mail::to($u)->send((new OrderShipped)->locale('fr'))`.
- **Outer PendingDispatch chain** (jobs and events) — modifiers after the dispatch returns a `PendingDispatch`: `ProcessOrder::dispatch($o)->onQueue('high')->onConnection('redis')->delay(60)`, `dispatch(new ProcessOrder($o))->afterCommit()`. The **Mail facade receiver chain** is also read: `Mail::to($u)->locale('fr')->mailer('ses')->send($m)`.

```json
{
  "file": "app/Services/Checkout.php",
  "line": 91,
  "method": "App\\Services\\Checkout::finalize",
  "overrides": { "connection": "redis", "queue": "high", "delay": 60 }
}
```

### Channel filter (`channels`, notifications only)

`$defs/dispatchSite` carries one further optional field — `channels` — emitted only on `notifications[*].notified_from` entries. It records the literal channel filter passed as the third argument to `Notification::send($users, $notification, $channels)` / `Notification::sendNow(...)`, which restricts that dispatch to a specific channel set and overrides the notification's own `via()`. Values use the same representation as `notifications[*].channels`: literal string channel names stored lowercased, `Class::class` channel constants stored as FQCN, in source order.

```json
{
  "file": "app/Services/Billing.php",
  "line": 88,
  "method": "App\\Services\\Billing::charge",
  "channels": ["mail", "App\\Channels\\SlackChannel"]
}
```

The key is omitted when the argument is absent, empty, or non-literal. It is captured only on the `Notification::send` / `Notification::sendNow` facade forms — the `->notify(...)` method form has no channel-filter argument, and the other three reverse-reference arrays (`events[*].dispatched_from`, `jobs[*].dispatched_from`, `mailables[*].sent_from`) never carry it. Full semantics live in [NotificationScanner](#notificationscanner).

### Kind classification

The cross-link pass disambiguates `kind: ambiguous` (Dispatchable form) before populating the other fields:

1. If `target` is in `events[]` (built by EventScanner), `kind = event`
2. Otherwise, `kind = job`

EventScanner's dispatch-site seeding ensures most Dispatchable-form events are already in `events[]`. Classes that aren't (jobs in `app/Jobs/`, internal classes that happen to use the Dispatchable trait) fall through to `job`.

### Confidence

`confidence` is always `"high"` for statically resolved targets. The `"medium"` and `"low"` enum values are reserved for future runtime overlay work.

### Expected behavior

- **Dispatch inside any method on a listener that handles an event via that method.** Contributes to that listener's `dispatches[]`. For example, a listener with `handles: [{event: OrderPlaced, method: handlePlaced}]` has the dispatches inside `handlePlaced()` attributed to it.
- **Dispatch inside an Eloquent hook method on an observer.** Contributes to that observer's `dispatches[]`.
- **Dispatch in a helper method NOT registered as a handler.** Doesn't appear in `listeners[*].dispatches` or `observers[*].dispatches` (because the method isn't in the handler set). May still contribute to `events[*].dispatched_from` if it dispatches an event.
- **Multiple dispatches in one method.** Each recorded separately.
- **Two ternary branches both resolving to concrete classes.** Two resolved sites emitted, one per branch. Not `conditional_dispatch`.
- **Dispatchable form `X::dispatch(...)` where `X` is in `events[]`.** Finalized as `kind: event`.
- **Dispatchable form `X::dispatch(...)` where `X` is in `app/Jobs/`.** Finalized as `kind: job` (since EventScanner's seeding rejects it under the `app/Events/` trim).
- **Top-level dispatch outside any class.** Skipped. No enclosing class context.
- **Dispatch inside a closure** (assigned to a variable, passed to a function, returned from a method). Skipped. Too easy to over-report — the closure may never execute, may execute in a different context, etc.
- **Dispatch inside an arrow function.** Skipped (closure depth counter).


## MailableScanner

Discovers Laravel mailable classes and emits the `mailables[]` section of
the index.

See [ADR 0003](adr/0003-mailables-notifications.md) for the
load-bearing design decisions (one section vs two, channel extraction,
cross-link wiring).

### What it detects

MailableScanner finds mailable classes via two discovery paths and
merges them by FQCN:

1. **Filesystem walk of `app/Mail/`.** Every `*.php` file under
   `app/Mail/` (recursively) is parsed and any concrete class found
   becomes a mailable candidate. Abstract classes, interfaces, traits,
   and anonymous classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by a recognised
   mail-dispatch call shape is located via the PSR-4 guess (leading
   `App\` → `app/`) and parsed. This lets mailables in DDD-style
   layouts like `app/Domain/Billing/Mail/InvoiceMailable.php` get picked
   up even though they live outside `app/Mail/`.

   Recognised call shapes (resolved via NameResolver):

   - `Mail::send(new X(...))` / `Mail::send(X::class)`
   - `Mail::queue(new X(...))` / `Mail::queue(X::class)`
   - `Mail::later($delay, new X(...))` / `Mail::later($delay, X::class)`
   - `Mail::to(...)->send(new X(...))` and the same chained off
     `->cc(...)`, `->bcc(...)`, `->locale(...)`, `->mailer(...)`
     (the visitor walks to the innermost `Mail::to/cc/bcc/...`
     receiver and accepts the chain regardless of the intermediate
     links).
   - `Mail::to(...)->queue(new X(...))`,
     `Mail::to(...)->later($delay, new X(...))`.

   The mailable *argument* may itself be wrapped in a fluent chain and
   still resolves to its target FQCN:
   `Mail::send((new OrderShipped)->onConnection('redis'))` and
   `Mail::to($user)->send((new OrderShipped)->locale('fr'))` both seed
   `OrderShipped`. Only `new X` / `X::class` argument receivers resolve
   through the chain — a variable receiver does not. The chain modifier
   values are captured into the dispatch site's `overrides` object —
   see Cross-link behavior. The Mail facade receiver chain
   (`Mail::to($u)->locale('fr')->mailer('ses')->send($m)`) is read
   as well.

Entries are deduped by FQCN. A mailable discovered through both paths
produces a single entry; the filesystem walk wins for `file`/`line`.

`queued: true` iff the class transitively implements
`Illuminate\Contracts\Queue\ShouldQueue` — either declared on its own
`implements` clause, or inherited via a parent class indexed under
`app/`. Resolved via the cross-file
[class hierarchy resolver](class-hierarchy.md). Inheritance
through vendor / framework classes that live outside `app/` is opaque
to the resolver and won't surface as `queued: true`.

### Output

One entry per mailable FQCN, conforming to `$defs/mailable`:

```json
{
  "fqcn": "App\\Mail\\OrderShipped",
  "file": "app/Mail/OrderShipped.php",
  "line": 18,
  "queued": true,
  "queue_config": {
    "connection": "redis",
    "queue": "mail",
    "delay": null,
    "tries": 3,
    "timeout": null,
    "backoff": null
  },
  "sent_from": []
}
```

`queue_config` is `null` when `queued: false`. When `queued: true` it
is an object with all six keys present — same shape as `$defs/job`'s
`queue_config`. Each value is either the scalar literal declared as a
class property, or `null` when the property is not declared. `null`
means "not declared"; Laravel applies its own defaults at runtime.

`sent_from` is always emitted as an empty array from the scanner.
It is populated by the cross-link pass from DispatchScanner's
per-call-site data.

Entries are sorted by `fqcn` ascending.

`stats.mailables` is added to the top-level stats block as the count
of entries.

### Cross-link behavior

- **`mailables[*].sent_from`** — for each dispatch site with finalized
  `kind === 'mailable'` whose `target` matches a mailable FQCN, a
  `$defs/dispatchSite` entry is appended. Identical shape to
  `events[*].dispatched_from` and `jobs[*].dispatched_from`. Sorted by
  `(file, line)`.

  When the dispatch site carries dispatch-time modifiers, the entry
  also carries an optional `overrides` object. For mailables both the
  inner argument-instance chain
  (`Mail::to($u)->send((new OrderShipped)->locale('fr'))`) and the Mail
  facade receiver chain
  (`Mail::to($u)->locale('fr')->mailer('ses')->send($m)`) are read.
  Source mapping: `->locale('fr')` → `locale`, `->mailer('ses')` →
  `mailer`, `->onConnection('sqs')` → `connection`, `->onQueue('mail')`
  → `queue`, `->delay(60)` → `delay` (integer seconds), `->afterCommit()`
  → `after_commit`. The key is omitted when no static modifier is
  present:

  ```json
  {
    "file": "app/Services/Checkout.php",
    "line": 94,
    "method": "App\\Services\\Checkout::finalize",
    "overrides": { "locale": "fr", "mailer": "ses" }
  }
  ```

  `overrides` records what the call site changed; `queue_config` still
  reflects the mailable's class-default property declarations.

The new `provisionalKind: 'mailable'` is emitted by
`DispatchSiteVisitor` for every recognised mail-dispatch shape and
joined in cross-link phase 5 against `mailables[*].fqcn`.

Mailables do not participate in the disambiguation phase (phase 2):
no recognised call shape is ambiguous with another primitive type.
`Mail::send(new X)` cannot be mistaken for a job dispatch.

### Expected behavior

- **Standard mailable**: `class OrderShipped extends Mailable implements
  ShouldQueue { use Queueable; }` — `queued: true`, `queue_config`
  populated from class-level scalar properties.
- **Mailable outside `app/Mail/`** dispatched via `Mail::send(new X)`:
  picked up via dispatch-site seeding, file/line from the class
  declaration.
- **Mailable in `app/Mail/` never dispatched anywhere**: picked up via
  filesystem walk, `sent_from: []`.
- **Same mailable dispatched many times**: one entry; every dispatch
  site contributes to `sent_from[]`.
- **`Mail::to($user)->cc($admins)->bcc($audit)->send(new X)`**: chain
  walked; one dispatch site emitted with `target: X`.
- **Dispatch site inside a closure or arrow function**: skipped, same
  rule as DispatchScanner (closures of every kind are out).
- **Parse error in a file**: `AstWalker` swallows it; no visitor hits
  for that file.


## NotificationScanner

Discovers Laravel notification classes and emits the `notifications[]`
section of the index.

See [ADR 0003](adr/0003-mailables-notifications.md) for the
load-bearing design decisions (one section vs two, channel extraction,
cross-link wiring).

### What it detects

NotificationScanner finds notification classes via two discovery paths
and merges them by FQCN:

1. **Filesystem walk of `app/Notifications/`.** Every `*.php` file
   (recursively) is parsed and any concrete class becomes a
   candidate. Abstract classes, interfaces, traits, and anonymous
   classes are skipped.

2. **Dispatch-site seeding.** Any class targeted by a recognised
   notification-dispatch shape is located via the PSR-4 guess
   (leading `App\` → `app/`) and parsed. This lets notifications in
   non-standard layouts like
   `app/Domain/Accounts/Notifications/InvitedNotification.php` be
   picked up.

   Recognised call shapes:

   - `$any->notify(new Y(...))` / `$any->notify(Y::class)` — the
     receiver is any expression (variable, property access, method
     call result). The visitor matches on the method name and the
     argument shape; it does not attempt to type-resolve the
     receiver. The `Notifiable` trait is Laravel's canonical
     receiver and `notify` is unique enough that false positives
     are negligible.
   - `$any->notifyNow(new Y(...))` — synchronous variant.
   - `Notification::send($recipients, new Y(...))`,
     `Notification::sendNow($recipients, new Y(...))` — the
     notification class lives at argument index 1. These two forms
     also accept an optional **channel filter** at argument index 2
     (`Notification::send($users, new Y(...), ['mail'])`), captured
     into the dispatch site's `channels` array — see Cross-link
     behavior.
   - `Notification::route('mail', '...')->notify(new Y(...))` and
     longer route chains
     (`Notification::route(...)->route(...)->notify(...)`).

   The notification *argument* may itself be wrapped in a fluent chain
   and still resolves to its target FQCN:
   `$user->notify((new InvoicePaid)->locale('es'))` and
   `Notification::send($users, (new InvoicePaid)->onQueue('emails'))`
   both seed `InvoicePaid`. Only `new Y` / `Y::class` argument
   receivers resolve through the chain — a variable receiver does not.
   The chain modifier values are captured into the dispatch site's
   `overrides` object — see Cross-link behavior. Notifications read the
   **inner argument-instance chain only**; modifiers on the Notification
   facade *before* `send` (`Notification::locale('es')->send($users, $n)`)
   are not detected (see the [troubleshooting guide](../guides/why-was-my-code-missed.md)).

Entries are deduped by FQCN. A notification discovered through both
paths produces a single entry; the filesystem walk wins for
`file`/`line`.

`queued: true` iff the class transitively implements
`Illuminate\Contracts\Queue\ShouldQueue`. Resolved via the cross-file
[class hierarchy resolver](class-hierarchy.md). Inheritance
through vendor / framework classes is opaque.

`channels[]` is extracted from the notification's `via($notifiable)`
method body — see below.

### Output

One entry per notification FQCN, conforming to `$defs/notification`:

```json
{
  "fqcn": "App\\Notifications\\InvoicePaid",
  "file": "app/Notifications/InvoicePaid.php",
  "line": 22,
  "queued": true,
  "queue_config": {
    "connection": null,
    "queue": "notifications",
    "delay": null,
    "tries": null,
    "timeout": null,
    "backoff": null
  },
  "channels": ["mail", "database", "slack"],
  "channels_dynamic": false,
  "notified_from": []
}
```

Field semantics:

- **`queued`, `queue_config`** — identical to `$defs/mailable` and
  `$defs/job`. `queue_config` is `null` when `queued: false`.
- **`channels[]`** — extracted from a `via()` method whose body is a
  single `return [...];` of literal strings and/or `Class::class`
  constants. Strings (`'mail'`, `'database'`, `'slack'`,
  `'broadcast'`, `'vonage'`) are stored verbatim, lowercased.
  Class-constant channels are stored as FQCN
  (`Illuminate\Notifications\Channels\SlackChannel`). Emitted in
  source order from the `via()` literal — **not** sorted. This matches
  Laravel's runtime channel-dispatch order and preserves the intent
  recorded in the file. Empty `[]` when the `via()` body is not
  statically resolvable, and also empty `[]` when no `via()` method is
  declared.
- **`channels_dynamic`** — `true` only when `via()` exists *and* its
  body is not the recognised single-return-literal-array shape
  (conditional logic, property/method access, variable indirection,
  keyed entries, non-literal items). `false` when the body is
  statically resolved, *and* `false` when the class declares no
  `via()` method at all. The distinction matters: absence of `via()`
  means "no channels declared" (an intentional zero); `via()` with an
  unresolvable body means "channels exist at runtime but static
  analysis can't see them". Consumers wanting "Slack notifications"
  filter on `"slack" in channels`; consumers triaging notifications
  whose channels Loom can't see filter on `channels_dynamic: true`.
- **`notified_from`** — always emitted as an empty array from the
  scanner. Populated by the cross-link pass.

Entries are sorted by `fqcn` ascending.

`stats.notifications` is added to the top-level stats block as the
count of entries.

### Cross-link behavior

- **`notifications[*].notified_from`** — for each dispatch site with
  finalized `kind === 'notification'` whose `target` matches a
  notification FQCN, a `$defs/dispatchSite` entry is appended.
  Identical shape to `events[*].dispatched_from`. Sorted by
  `(file, line)`.

  When the dispatch site carries dispatch-time modifiers on the inner
  argument-instance chain
  (`$user->notify((new InvoicePaid)->onQueue('emails')->delay(60))`),
  the entry also carries an optional `overrides` object. Source
  mapping: `->onQueue('emails')` → `queue`, `->onConnection('redis')` →
  `connection`, `->delay(60)` → `delay` (integer seconds),
  `->afterCommit()` → `after_commit`, `->locale('es')` → `locale`,
  `->mailer(...)` → `mailer`. The key is omitted when no static
  modifier is present:

  ```json
  {
    "file": "app/Services/Billing.php",
    "line": 51,
    "method": "App\\Services\\Billing::charge",
    "overrides": { "queue": "emails" }
  }
  ```

  Notifications capture the inner argument-instance chain only. The
  Notification facade-receiver form
  (`Notification::locale('es')->send($users, $n)`) is not detected.
  `overrides` records what the call site changed; `queue_config` still
  reflects the notification's class-default property declarations.

  When the dispatch site is a `Notification::send` / `Notification::sendNow`
  facade call carrying a literal **channel filter** at argument index 2
  (`Notification::send($users, new InvoicePaid(), ['mail', SlackChannel::class])`),
  the entry also carries an optional `channels` array. The filter
  restricts that dispatch to the given channel set, overriding the
  notification's own `via()` declaration for that call. Values use the
  same representation as `notifications[*].channels` from `via()`:
  literal string channel names are stored lowercased (`'MAIL'` →
  `"mail"`) and `Class::class` channel constants are stored as their
  FQCN (`SlackChannel::class` → `"App\\Channels\\SlackChannel"`), in
  source order:

  ```json
  {
    "file": "app/Services/Billing.php",
    "line": 88,
    "method": "App\\Services\\Billing::charge",
    "channels": ["mail", "App\\Channels\\SlackChannel"]
  }
  ```

  The key is omitted entirely when the argument is absent, empty
  (`[]`), or non-literal (a `$variable`, a method call, or any item
  that isn't a literal string or `::class` constant) — a site without a
  resolvable filter stays byte-identical to before this field existed
  (no empty array; the key simply isn't present). The filter is
  captured **only** on the `Notification::send` / `Notification::sendNow`
  facade forms; the `$any->notify(...)` / `notifyNow(...)` method forms
  have no channel-filter argument, and the
  `Notification::route(...)->notify(...)` chain carries recipient
  routing (where the notification is sent), not a channel filter — no
  `channels` is emitted on those sites.

The new `provisionalKind: 'notification'` is emitted by
`DispatchSiteVisitor` for every recognised notification-dispatch
shape and joined in cross-link phase 5 against
`notifications[*].fqcn`.

Notifications do not participate in the disambiguation phase
(phase 2): the recognised call shapes (`$x->notify(...)`,
`Notification::send(...)`) are unambiguous.

### Expected behavior

- **Standard notification with static `via()`**: `public function
  via($notifiable) { return ['mail', 'database']; }` —
  `channels: ["mail", "database"]`, `channels_dynamic: false`. Source
  order is preserved.
- **`via()` returning class constants**: `return [SlackChannel::class,
  'database'];` — `channels: ["Illuminate\\Notifications\\Channels\\SlackChannel",
  "database"]`, in source order.
- **`via()` with conditional logic**: `return $notifiable->prefers
  ? ['mail'] : ['database'];` — `channels: []`,
  `channels_dynamic: true`.
- **`via()` referencing a property**: `return $this->channels;` —
  `channels: []`, `channels_dynamic: true`.
- **No `via()` declared**: `channels: []`, `channels_dynamic: false`.
  The class declared no channels; this is an intentional zero, not an
  unknown. (Would throw at runtime, but the scanner doesn't crash on
  it.)
- **Notification dispatched via `$user->notify(new X)` where `$user`
  is `Notification::route(...)` chained**: same target extraction;
  one `notified_from` entry per call site.
- **Same notification dispatched many times**: one entry; every
  dispatch site contributes to `notified_from[]`.
- **Dispatch site inside a closure or arrow function**: skipped,
  same rule as DispatchScanner.


## ScheduleScanner

Discovers entries declared in Laravel's task scheduler and emits the
`scheduled[]` section of the index.

See [ADR 0002](adr/0002-schedule-scanner.md) for the load-bearing design
decisions (discovery strategy, cron normalisation, cross-link shape).

### What it detects

ScheduleScanner walks three discovery surfaces and merges results by
`(file, line)`:

1. **`Console\Kernel::schedule(Schedule $schedule)`** — any class
   declaration in `app/Console/Kernel.php` carrying a `schedule()` method
   whose first parameter is typed `Illuminate\Console\Scheduling\Schedule`.
   The method body is walked.

2. **`bootstrap/app.php` `->withSchedule(...)`** — the
   `Application::configure(...)` chain is located, the
   `->withSchedule(function (Schedule $schedule) { ... })` link is found,
   and the closure body is walked.

3. **`Schedule` facade calls** — every PHP file under `app/` is parsed,
   and every top-level expression of the form
   `Schedule::call(...)`, `Schedule::command(...)`, `Schedule::job(...)`,
   or `Schedule::exec(...)` is captured (where `Schedule` resolves to
   `Illuminate\Support\Facades\Schedule`). Useful for schedules registered
   in service providers or in package boot logic.

Each captured root call is the start of a fluent chain. The visitor walks
down the chain collecting `(methodName, args)` for every link, then
emits one entry per chain.

#### Schedule groups

A `->group(Closure)` link applies its outer chain's attributes to every
task declared inside the closure:

```php
Schedule::daily()->onOneServer()->group(function () {
    Schedule::command('report:a');
    Schedule::command('report:b')->weekly();
});
```

Each inner task emits as an ordinary `scheduled[]` entry — there is no
special marker — carrying the group's merged attributes (cron, frequency,
`on_one_server`, `without_overlapping`, `timezone`, constraints, …). The
group's links are spliced *before* the inner task's own links, so the
task's own modifiers win on conflict (last-wins, matching Laravel's
runtime). Above, `report:a` inherits the group's `daily` cron and
`on_one_server`; `report:b` inherits `on_one_server` but its own
`->weekly()` overrides the group's `daily`.

Both the variable form (`$schedule->...->group(...)`) and the facade form
(`Schedule::...->group(...)`) are recognised. Nested groups concatenate —
each enclosing group's attributes apply to the inner tasks, outermost
first.

### Output

One entry per chain, conforming to `$defs/scheduleEntry`:

```json
{
  "kind": "command",
  "name": "send-daily-mail",
  "target": "mail:send {--queue=default}",
  "arguments": [],
  "queue": null,
  "connection": null,
  "cron": "0 13 * * *",
  "frequency": null,
  "timezone": "America/Chicago",
  "without_overlapping": true,
  "without_overlapping_expires_at": null,
  "on_one_server": false,
  "run_in_background": false,
  "even_in_maintenance_mode": false,
  "constraints": ["weekdays"],
  "file": "app/Console/Kernel.php",
  "line": 28
}
```

Field semantics:

- **`kind`** — `command` | `job` | `closure` | `exec`. Determined by the
  root method of the chain (`command`, `job`, `call`, `exec`).
- **`name`** — string or `null`. The schedule task's label from
  `->name('...')`; `null` when `->name()` is absent or its argument is not
  a statically-resolvable string literal (same resolution rule as
  `timezone`). This is the unique key Laravel uses for `onOneServer`
  deduplication.
- **`target`** — depends on `kind`:
  - `command`: the signature string (`"mail:send"`,
    `"mail:send {--queue=default}"`) or, when the argument is a class
    constant (`SendMail::class`), the FQCN.
  - `job`: the FQCN, resolved via NameResolver from `new X(...)` or
    `X::class`.
  - `closure`:
    - `null` for inline closures (`fn () => ...`, `function () { ... }`);
      identification falls back to `file:line`.
    - `"FQCN::method"` for callable tuples (`[Cls::class, 'method']`) and
      Laravel callable strings (`'App\\Cls@method'`).
  - `exec`: the shell command string verbatim.
- **`arguments[]`** — the literal parameters array passed to
  `->command(Class::class, [...])`. `command` kind only; `[]` for every
  other kind. Plain items render as the stringified value; keyed items
  render as `"key=value"`; booleans as `"true"`/`"false"`; items that
  aren't statically resolvable are skipped. Example:
  `->command(SendEmails::class, ['--force', 'user' => 1])` →
  `["--force", "user=1"]`.
- **`queue`** — string or `null`. `job` kind only: the queue override
  from `->job($job, $queue, $connection)` (2nd argument). `null` when
  absent or for non-`job` kinds.
- **`connection`** — string or `null`. `job` kind only: the connection
  override from `->job($job, $queue, $connection)` (3rd argument). `null`
  when absent or for non-`job` kinds.
- **`cron`** — five-field normalised cron expression (`"*/5 * * * *"`) or
  `null` if no recognised frequency helper appears in the chain. The
  recognised set is enumerated in ADR 0002 §3. Also `null` for sub-minute
  helpers, whose interval is carried in `frequency` instead (see below).
- **`frequency`** — structured sub-minute interval
  `{ "unit": "seconds", "every": <N> }`, or `null`. Set only by the seven
  sub-minute helpers, which a five-field cron cannot express:
  - `everySecond` → `every: 1`, `everyTwoSeconds` → `2`,
    `everyFiveSeconds` → `5`, `everyTenSeconds` → `10`,
    `everyFifteenSeconds` → `15`, `everyTwentySeconds` → `20`,
    `everyThirtySeconds` → `30`.

  These entries carry `cron: null`. For every other entry `frequency` is
  `null`. At most one of `cron` / `frequency` is non-null on any entry.
  `unit` is backed by the `FrequencyUnit` enum (`"seconds"` only today). See
  [ADR 0004](adr/0004-sub-minute-frequencies.md).
- **`timezone`** — string from `->timezone('America/Chicago')` or `null`.
- **`without_overlapping`** — `true` if `->withoutOverlapping()` appears.
- **`without_overlapping_expires_at`** — integer or `null`. The expiry
  minutes from `->withoutOverlapping($minutes)`. `null` when
  `withoutOverlapping()` is called with no literal argument — the
  framework default of 1440 is deliberately not fabricated (honesty over
  completeness). Independent of the `without_overlapping` boolean, which
  is unchanged.
- **`on_one_server`** — `true` if `->onOneServer()` appears.
- **`run_in_background`** — `true` if `->runInBackground()` appears.
- **`even_in_maintenance_mode`** — `true` if `->evenInMaintenanceMode()`
  appears.
- **`constraints[]`** — opaque string labels for non-cron restrictions.
  Sorted ascending. Recognised shapes:
  - Day-of-week: `"weekdays"`, `"weekends"`, `"sundays"`, `"mondays"`,
    `"tuesdays"`, `"wednesdays"`, `"thursdays"`, `"fridays"`,
    `"saturdays"`.
  - Time-window: `"between(8:00,17:00)"`, `"unlessBetween(8:00,17:00)"`.
    Falls back to `"between(closure)"` / `"unlessBetween(closure)"` when
    the arguments aren't scalar strings.
  - Conditional: `"when(closure)"`, `"skip(closure)"`. The closure body
    is not analysed.
  - Environment: `"environments(production,staging)"` for scalar args
    (including a single array literal); `"environments(closure)"`
    otherwise.
  - Day-of-week list: `"days(0,3)"` for `->days(0, 3)` and
    `->days([0, 3])` (variadic ints or a single array literal); falls
    back to `"days(?)"` when no scalar day can be resolved. Surfaced as a
    constraint rather than folded into the cron, and does not clear a
    cron set earlier in the chain.
- **`file`, `line`** — the position of the root method call in the chain
  (`$schedule->command(...)`, not the trailing `->onOneServer()`).

Entries are sorted by `(file, line)` ascending.

`stats.scheduled` is added to the top-level stats block as the count of
entries.

### Cross-link behavior

ScheduleScanner participates in cross-link only as a *source*. No fields
on existing primitives are widened.

- `scheduled[*].target` with `kind: "job"` carries the job FQCN.
  Consumers join client-side against `jobs[*].fqcn`. There is no
  `jobs[*].scheduled` flag — see ADR 0002 §5 for rationale.
- Dispatch sites inside a scheduled closure (`Schedule::call(fn () =>
  event(new X))`) are **not** captured by DispatchScanner. Closures of
  every kind are a documented skip in `dispatches.md`.

### Expected behavior

- **Standard kernel form**: `protected function schedule(Schedule
  $schedule) { $schedule->command('foo:bar')->daily(); }` — one entry,
  `cron: "0 0 * * *"`.
- **L11 closure form**: `->withSchedule(function (Schedule $schedule) {
  $schedule->job(new ProcessOrder)->hourly(); })` — one entry,
  `cron: "0 * * * *"`, `kind: "job"`, target FQCN-resolved.
- **Facade form in provider boot**: `Schedule::command('queue:work')
  ->everyMinute();` — picked up regardless of which class hosts the call.
- **Multiple modifiers**: every link in the chain is inspected; flags
  set, name and timezone captured, constraints collected.
- **`name(string)`**: `->name('send-daily-mail')` is captured into
  `name`. A non-literal argument (`->name($label)`) leaves `name: null`;
  the rest of the chain is still captured.
- **`evenInMaintenanceMode()`**: `->evenInMaintenanceMode()` sets
  `even_in_maintenance_mode: true`; absent, the flag is `false`.
- **`cron(string)` passthrough**: `->cron('*/5 8-17 * * 1-5')` is stored
  verbatim in `cron`.
- **Multi-hour helpers honour their minutes argument**:
  `->everyTwoHours(15)` → `cron: "15 */2 * * *"`; the same applies to
  `everyThreeHours`, `everyFourHours`, and `everySixHours`. No-arg calls
  are unchanged (`->everyTwoHours()` → `"0 */2 * * *"`).
- **`everyOddHour`**: `->everyOddHour()` → `cron: "0 1-23/2 * * *"`,
  honouring an optional minutes argument (`->everyOddHour(30)` →
  `"30 1-23/2 * * *"`).
- **`quarterlyOn`**: `->quarterlyOn(15, '13:00')` →
  `cron: "0 13 15 1-12/3 *"` (day-of-quarter and time both honoured;
  both default — day `1`, time `0:00`).
- **`daysOfMonth`**: `->daysOfMonth(1, 15)` → `cron: "0 0 1,15 * *"`.
  Accepts variadic ints (`->daysOfMonth(1, 15)`) or a single array
  literal (`->daysOfMonth([2, 16])`); Laravel runs these at 00:00.
  Unlike the day-of-week `->days(...)` constraint, this is a frequency
  helper that produces a cron and chains safely after another frequency
  (`->daily()->daysOfMonth(10)` → `"0 0 10 * *"`). `cron: null` when no
  day argument resolves statically (`->daysOfMonth($var)`).
- **Sub-minute helpers**: `->everyTenSeconds()` →
  `cron: null`, `frequency: { "unit": "seconds", "every": 10 }`. The seven
  sub-minute helpers (`everySecond` … `everyThirtySeconds`) can't be a
  five-field cron, so the interval lives in `frequency`.
- **Last-wins on conflicting frequencies**: chain with multiple frequency
  helpers (`->daily()->hourly()`) reflects the last one. Matches
  Laravel's runtime behaviour. This holds across the cron / sub-minute
  boundary too: `->everyTenSeconds()->daily()` yields `cron: "0 0 * * *"`,
  `frequency: null`; `->daily()->everyTenSeconds()` yields `cron: null`,
  `frequency: { "unit": "seconds", "every": 10 }`. At most one is non-null.
- **Schedule groups**: a task inside `->group(Closure)` inherits the
  outer chain's frequency and modifiers. `Schedule::daily()->onOneServer()
  ->group(fn () => Schedule::command('a'))` → one entry for `a` with
  `cron: "0 0 * * *"`, `on_one_server: true`. An inner frequency overrides
  the group's: `->daily()->group(fn () => Schedule::command('b')
  ->weekly())` → `b` with `cron: "0 0 * * 0"`. Nested groups concatenate.
- **Tuple-callable in `->call`**: `->call([Reporter::class, 'send'])`
  emits `kind: "closure"`, `target: "App\\Reporter::send"`.
- **`Class@method` callable in `->call`**: `->call('App\\Reporter@send')`
  normalised to `"App\\Reporter::send"`.


## RouteScanner

Discovers HTTP routes registered in `routes/*.php` and emits the
`routes[]` section of the index.

Route discovery covers individual registrations and the context of any
enclosing `Route::group(...)` — prefix, name prefix, default controller,
and middleware (see [Route groups](#route-groups) and
[Middleware](#middleware)). `Route::resource()` / `Route::apiResource()`
registrations are expanded into their constituent CRUD routes (see
[Resource controllers](#resource-controllers)). Each route also carries a
`dispatches[]` cross-link — the events and jobs dispatched inside its
controller method, joined during the cross-link pass (see
[Dispatches](#dispatches)). Middleware-group / alias resolution is deferred
to follow-up PRs (see [Known limitations](../reference/what-loom-detects.md#routes)).

### What it detects

RouteScanner statically parses every `*.php` file under the app's
`routes/` directory (no Laravel boot) and captures each route
registration it can resolve. The recognised root forms are:

1. **Verb methods** — `Route::get('/uri', action)` and the same for
   `post`, `put`, `patch`, `delete`, and `options`. One entry per call,
   `method` set to the uppercased verb.

2. **`Route::any('/uri', action)`** — one entry with `method` set to
   `ANY`.

3. **`Route::match([...], '/uri', action)`** — the first argument is a
   list of verb strings. One entry is emitted **per listed verb**, each
   sharing the same `uri`, `name`, and controller fields.

The route name is captured from a chained `->name('...')` link on the
registration.

#### Action forms

The action argument (the last positional argument, or the second for
`match`) is resolved into `controller_fqcn` / `controller_method`:

- **Tuple** — `[UserController::class, 'show']` →
  `controller_fqcn: "App\\Http\\Controllers\\UserController"`,
  `controller_method: "show"`.
- **Invokable** — bare `InvokeController::class` or single-element
  `[InvokeController::class]` →
  `controller_method: "__invoke"`.
- **Legacy string** — `'UserController@show'` is split on `@` into FQCN
  and method.
- **Closure** — `function () { ... }` or `fn () => ...` → both controller
  fields `null`.

### Output

One entry per route (one **per verb** for `match`), conforming to
`$defs/route`:

```json
{
  "method": "GET",
  "uri": "/admin/users/{id}",
  "name": "admin.users.show",
  "controller_fqcn": "App\\Http\\Controllers\\UserController",
  "controller_method": "show",
  "middleware": ["web", "auth"],
  "file": "routes/web.php",
  "line": 14,
  "dispatches": [
    {
      "target": "App\\Events\\UserViewed",
      "kind": "event",
      "confidence": "high",
      "file": "app/Http/Controllers/UserController.php",
      "line": 31
    }
  ]
}
```

(The `uri`, `name`, and `middleware` above reflect an enclosing
`Route::prefix('admin')->name('admin.')->middleware(['web', 'auth'])` group
applied to a leaf `Route::get('/users/{id}', ...)->name('users.show')`.)

Field semantics:

- **`method`** — HTTP verb, uppercased. One of `GET`, `POST`, `PUT`,
  `PATCH`, `DELETE`, `OPTIONS`, or `ANY` (the synthetic verb for
  `Route::any`). A `Route::match(['get', 'post'], ...)` registration
  yields two entries, `GET` and `POST`.
- **`uri`** — the route URI, with the prefixes of any enclosing
  `Route::group(...)` prepended (e.g. `/admin/users/{id}`). Always begins
  with a leading slash; the root is `/`. See
  [Route groups](#route-groups).
- **`name`** — the named-route name from `->name('...')`, with any
  enclosing group name prefix concatenated as-is (see
  [Route groups](#route-groups)); `null` when the registration carries no
  `->name()` link and no group name prefix applies (or the argument is not
  a statically-resolvable string literal).
- **`controller_fqcn`** — the fully-qualified controller class for the
  action, or `null` for closures and unresolvable actions.
- **`controller_method`** — the action method; `__invoke` for invokable
  controllers; `null` when `controller_fqcn` is `null`.
- **`middleware`** — the resolved middleware chain for the route: the
  middleware of every enclosing group (outermost first) followed by the
  route's own `->middleware(...)`, in source order, with exact-duplicate
  names deduped. `[]` when neither the route nor any enclosing group
  declares middleware. See [Middleware](#middleware).
- **`file`** — path to the route file, relative to the app root (e.g.
  `routes/web.php`).
- **`line`** — 1-indexed line of the route registration.
- **`dispatches`** — the events and jobs dispatched inside the route's
  controller method, each a `$defs/dispatch` reference
  (`{target, kind, confidence, file, line}` — the same shape as
  `listeners[*].dispatches` / `jobs[*].dispatches`). Populated by the
  cross-link pass; `[]` for closure routes, unresolved-controller routes,
  and any route whose controller method dispatches nothing. See
  [Dispatches](#dispatches).

`stats.routes` is added to the top-level stats block as the count of
entries.

### Expected behavior

- **Verb form**: `Route::get('/users', [UserController::class, 'index'])`
  — one entry, `method: "GET"`, controller fields resolved.
- **`any` form**: `Route::any('/webhook', [HookController::class, 'handle'])`
  — one entry, `method: "ANY"`.
- **`match` form**: `Route::match(['get', 'post'], '/search', [SearchController::class, 'run'])`
  — two entries (`GET` and `POST`), identical except for `method`.
- **Invokable controller**: `Route::get('/dashboard', DashboardController::class)`
  or `Route::get('/dashboard', [DashboardController::class])` — one entry,
  `controller_method: "__invoke"`.
- **Legacy string action**: `Route::get('/users', 'UserController@index')`
  — split on `@` into FQCN and method.
- **Named route**: `Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show')`
  — `name: "users.show"`.
- **Closure action**: `Route::get('/ping', fn () => 'pong')` — one entry,
  both controller fields `null`.

#### Route groups

Routes declared inside a `Route::group(...)` inherit the group's context.
Both syntaxes are supported: the array-config form
`Route::group(['prefix' => 'admin', 'as' => 'admin.', 'controller' => C::class], fn)`
and the fluent form
`Route::prefix('admin')->name('admin.')->controller(C::class)->group(fn)`.
Three pieces of context are merged into the leaf route:

- **Prefix** — the group's `prefix` segments are prepended to the route
  URI. The result always carries a leading slash, and the root is `/`.
  `Route::prefix('admin')->group(fn () => Route::get('/panel', ...))`
  yields `uri` `/admin/panel`; `Route::get('/', ...)` inside
  `prefix('admin')` yields `/admin`. Nested groups concatenate, outer to
  inner: `v2` > `users` > `/{id}` yields `/v2/users/{id}`. Ungrouped
  routes are unchanged (`/users`, `/`).

- **Name prefix** — the group's `as` / `name(...)` prefix is concatenated
  **as-is** (no separator inserted) with the leaf route's `->name(...)`.
  `name('admin.')` + leaf `->name('users')` yields `admin.users`. Nested
  prefixes chain: `admin.` > `users.` > `index` yields `admin.users.index`.
  A group name prefix with no leaf `->name()` yields just the prefix (e.g.
  `admin.`).

- **Default controller** — the group's `controller(C::class)` resolves a
  **bare method-name** string action to that controller.
  `Route::controller(UserController::class)->group(fn () => Route::get('/u', 'store'))`
  yields `controller_fqcn` `UserController`, `controller_method` `store`.
  It does **not** override an action that already names a class — an array
  tuple, a bare `Ctrl::class`, or a `'Class@method'` string keeps its own
  controller.

#### Middleware

Each route's `middleware` field is the **resolved chain** Loom can see
statically: the middleware of every enclosing group, outermost group to
innermost, followed by the route's own `->middleware(...)`, in source
order. Exact-duplicate names are deduped (first occurrence wins);
otherwise order is preserved.

Recognised forms, on both the route and a group:

- Single string — `->middleware('auth')`.
- Array — `->middleware(['auth', 'verified'])`.
- Chained — `->middleware('a')->middleware('b')` accumulates `a`, `b`.
- Variadic — `->middleware('a', 'b')`.
- `::class` reference — resolved to the FQCN (e.g.
  `->middleware(EnsureTokenIsValid::class)` →
  `"App\\Http\\Middleware\\EnsureTokenIsValid"`).
- Group middleware — via the fluent form
  `Route::middleware([...])->group(...)` or the array-config form
  `Route::group(['middleware' => [...]], fn)`.

Middleware arguments with parameters are kept **verbatim**, including the
parameter list: `->middleware('throttle:60,1')` records `"throttle:60,1"`,
and `->middleware('can:update,post')` records `"can:update,post"`.

Worked example:

```php
Route::middleware(['web', 'auth'])->prefix('account')->group(function () {
    Route::get('/settings', [AccountController::class, 'settings'])
        ->middleware('verified');
});
```

The `/account/settings` route resolves to
`middleware: ["web", "auth", "verified"]` — the two group entries
(outermost first) followed by the route's own `verified`.

#### Resource controllers

`Route::resource('photos', PhotoController::class)` is **expanded** into
its constituent CRUD routes — one `routes[]` entry per generated action.
A full resource expands to **seven** entries:

| Action  | method   | uri                    | name             | controller_method |
| ------- | -------- | ---------------------- | ---------------- | ----------------- |
| index   | `GET`    | `/photos`              | `photos.index`   | `index`           |
| create  | `GET`    | `/photos/create`       | `photos.create`  | `create`          |
| store   | `POST`   | `/photos`              | `photos.store`   | `store`           |
| show    | `GET`    | `/photos/{photo}`      | `photos.show`    | `show`            |
| edit    | `GET`    | `/photos/{photo}/edit` | `photos.edit`    | `edit`            |
| update  | `PUT`    | `/photos/{photo}`      | `photos.update`  | `update`          |
| destroy | `DELETE` | `/photos/{photo}`      | `photos.destroy` | `destroy`         |

`controller_fqcn` is the resource controller for every entry
(`App\Http\Controllers\PhotoController` above).

`Route::apiResource('photos', PhotoController::class)` expands to the same
set **minus** the two HTML-form actions, `create` and `edit` — **five**
entries: `index`, `store`, `show`, `update`, `destroy`.

- **Member parameter** — the `{...}` segment is Laravel's `Str::singular`
  of the resource name: `photos` → `{photo}`, `categories` →
  `{category}`. The same singular is used for `show`, `edit`, `update`,
  and `destroy`.

- **`update` verb** — emitted as `PUT`. Laravel registers `update` for
  **both** `PUT` and `PATCH`; this scanner emits a single `PUT` entry for
  it (the `PATCH` alias is not separately synthesized).

- **Group / middleware inheritance** — a `resource()` / `apiResource()`
  call inside a `Route::group(...)` (or fluent `prefix`/`name`/`middleware`
  chain) propagates the enclosing context to **all** generated sub-routes:
  the group prefix is prepended to every `uri`, the group name prefix is
  concatenated onto every `name`, and the group middleware is merged into
  every entry's `middleware` chain — exactly as for leaf routes (see
  [Route groups](#route-groups) and [Middleware](#middleware)).

- **`->only([...])` / `->except([...])`** — these filter the generated
  action set. `Route::resource('photos', ...)->only(['index', 'show'])`
  emits just the `index` and `show` entries; `->except(['create', 'edit'])`
  emits the other five. Both are honoured; if both are present Laravel's
  precedence applies.

The override forms `->names(...)`, `->parameters(...)`, `->scoped(...)`,
and `->shallow()` are **not** applied — the expansion always uses the
default names and parameters above (see
[Known limitations](../reference/what-loom-detects.md#routes)).

#### Dispatches

Each route's `dispatches` field lists the events and jobs dispatched inside
its controller method. It is the cross-section link issue #8 is fundamentally
about: an event can now be traced back through the controller that dispatches
it to the route that reaches that controller.

The field is filled by the `RouteDispatchAttributionPhase` cross-link phase,
not by the scanner itself — the raw scanner output seeds `dispatches: []`,
and the phase fills it in. The match is by **(controller_fqcn,
controller_method)**: every dispatch site DispatchScanner records carries its
enclosing class and method, and a site is attributed to a route when that
enclosing class+method equals the route's resolved controller and action. A
controller method dispatching an event and a job lists both; the same
controller method behind several routes (e.g. a `match` registration, or a
resource action) attaches the dispatches to every matching route entry.

What is captured: event and job dispatches — the same kinds the dispatch
attribution emits elsewhere (each finalized to `kind: "event"` or
`kind: "job"`). Mailables and notifications are not listed here; they are
tracked through their own reverse-links (`mailables[*].sent_from`,
`notifications[*].notified_from`).

`dispatches` is `[]` for:

- **Closure routes** — no controller identity to key on.
- **Unresolved-controller routes** — `controller_fqcn` / `controller_method`
  are `null`, so no site can match.
- **Controller methods that dispatch nothing.**

Because the field is populated during the cross-link pass, it is empty unless
the index was built through `IndexBuilder` with `DispatchScanner` registered —
which the `loom:scan` CLI always does.

