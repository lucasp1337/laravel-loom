# ListenerScanner

Discovers event listeners and emits the `listeners[]` section of the index.

> Closure-based listeners (arrow functions and `function () { … }` values in `$listen`, `Event::listen()`, or subscriber return-arrays) are emitted into the separate `closure_listeners[]` section instead — they have no FQCN to key against the `listeners[]` shape. See [closure-listeners.md](closure-listeners.md).

## What it detects

ListenerScanner uses four discovery paths and merges them by listener FQCN:

1. **Auto-discovery via `app/Listeners/`.** Every class in `app/Listeners/` with a public `handle()` method is a listener candidate. The first parameter's type-hint becomes the event the listener handles. Classes that transitively implement `Illuminate\Contracts\Queue\ShouldQueue` — directly or via a parent class indexed under `app/` — are marked `queued: true`.

2. **`$listen` array on `EventServiceProvider`.** Walks the entire `app/` tree (not just `app/Providers/`) and looks at classes named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. The `$listen` property (any visibility, must be `array`) is parsed: each `EventClass::class => [Listener::class, …]` pair becomes a registration. Bare `Listener::class` values map to `method: "handle"`. Tuple values `[Listener::class, 'method']` preserve the method name. Resolvable callable values — `Closure::fromCallable([Listener::class, 'method'])`, `Closure::fromCallable([Listener::class])` (method defaults to `"handle"`), and `Listener::method(...)` first-class callable syntax — resolve to the same FQCN+method and route through the same merge as the literal tuple.

3. **`listen()` calls on the event dispatcher.** Walks the entire `app/` tree for `listen(EventClass::class, Listener::class)` calls made against the event dispatcher — both the `\Illuminate\Support\Facades\Event::listen(...)` facade form and the container-resolved forms (see [Container-form registrations](#container-form-registrations) below). Bare `Listener::class` second arguments map to `method: "handle"`; tuple form `[Listener::class, 'method']` preserves the method name. Resolvable callable second arguments — `Closure::fromCallable([Listener::class, 'method'])`, `Closure::fromCallable([Listener::class])`, and `Listener::method(...)` first-class callable syntax — resolve identically and route through the same merge. The class-shape filter only applies in path 2 — these calls are scanner-agnostic about the surrounding class, so providers in DDD-style layouts (e.g. `app/Domain/Invoicing/Providers/InvoicingServiceProvider.php`) are discovered. All forms emit `registration: event_listen_call`.

4. **Subscribers.** Classes registered via the `$subscribe = [SubscriberClass::class, …]` array on an `EventServiceProvider`, or via `Event::subscribe(SubscriberClass::class)` calls. The subscriber's own `subscribe($events): array` method is then parsed in two complementary ways — see [Subscribers](#subscribers) below. Subscribers receive `registration: "subscriber"` — the highest-precedence source.

## Subscribers

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

## Container-form registrations

Beyond the `Event::` facade, a `listen()` call against an event dispatcher resolved from the container is recognized. These route through the same path-3 merge and emit `registration: event_listen_call` — there is no separate registration source and no schema change.

The dispatcher is identified by FQCN: `Illuminate\Contracts\Events\Dispatcher`, `Illuminate\Events\Dispatcher`, or the bare `Dispatcher` basename. The recognized receiver shapes are:

| Receiver | Example |
|---|---|
| App container array-access (literal `'events'` key) | `$this->app['events']->listen($event, $listener)` |
| `app()` helper with a Dispatcher FQCN | `app(\Illuminate\Contracts\Events\Dispatcher::class)->listen(...)` |
| `resolve()` helper with a Dispatcher FQCN | `resolve(Dispatcher::class)->listen(...)` |
| `$this->app->make(...)` / `->makeWith(...)` with a Dispatcher FQCN | `$this->app->make(Dispatcher::class)->listen(...)` |
| A local variable assigned from any of the above earlier in the same scope | `$dispatcher = app(Dispatcher::class); … $dispatcher->listen(...)` |

Every listener form supported on the facade is supported here too: `Listener::class`, the tuple `[Foo::class, 'method']`, `Closure::fromCallable([...])`, the first-class `Foo::method(...)` form, and inline closures (which route to `closure_listeners[]` — see [closure-listeners.md](closure-listeners.md)).

Variable tracking is a single flat pass within the file (assignment-precedes-use): the variable must be assigned from a recognized dispatcher expression before the `listen()` call. Reassigning the variable to a non-dispatcher value invalidates tracking from that point.

## Output

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

## Multi-handler listeners

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

## Expected behavior

- **Listener registered via multiple paths.** Single entry. `handles` is the union of `(event, method)` tuples; `registration` is the highest-precedence source.
- **Listener with typed `handle(OrderPlaced $event)`.** Auto-discovered. `handles: [{ "event": "App\\Events\\OrderPlaced", "method": "handle" }]` (or whatever the resolved type-hint is).
- **Listener with `handle($event)` (no type-hint).** Auto-discovered with `handles: []`. The listener is still registered; it just doesn't auto-discover a target event. Other paths may still add entries.
- **Listener listed in `$listen` but located outside `app/Listeners/`.** Picked up via the PSR-4 guess (leading `App\` → `app/`). If the file can't be located on disk, the listener is dropped (the schema requires `file` and `line`).
- **`$listen` tuple form `[Listener::class, 'method']`.** Both the listener FQCN and the method name are recorded. The resulting `handles[]` entry is `{event: …, method: "method"}`.
- **`Event::listen(Event::class, [Listener::class, 'method'])`.** Method name preserved. Bare `Event::listen(Event::class, Listener::class)` maps to `method: "handle"`.
- **Resolvable callable forms.** `Closure::fromCallable([Listener::class, 'method'])` and `Listener::method(...)` first-class callable syntax both resolve to a regular `listeners[]` entry for `Listener::method`. The single-element `Closure::fromCallable([Listener::class])` mirrors bare `::class` and maps to `method: "handle"`. Valid in both the `$listen` array (`listen_array`) and `Event::listen()` (`event_listen_call`); the resolved FQCN+method flows through the same merge as the literal `[Listener::class, 'method']` tuple.
- **`Event::listen()` inside a non-provider class.** Discovered. The `Event::listen` visitor doesn't filter by surrounding class shape — it accepts any static call.
- **`Event::listen(\Illuminate\Support\Facades\Event::class, ...)` fully qualified.** Resolved correctly via NameResolver.
- **`ShouldQueue` implemented transitively.** `queued: true` whenever any class in the listener's `extends` chain (or the listener itself) carries `implements ShouldQueue`. Resolution uses the cross-file [class hierarchy resolver](../support/class-hierarchy.md).

## Known limitations

- **Dynamic event names.** `Event::listen($variable, Listener::class)` is skipped.
- **Param-typed-only dispatchers.** `function boot(Dispatcher $dispatcher) { $dispatcher->listen(...); }` is not matched. There is no assignment expression to track — the dispatcher arrives as a typed parameter — so the variable-tracking pass has nothing to anchor on. This is the most idiomatic Laravel form and is the primary known gap; it's a candidate follow-up.
- **Typed property receivers.** `$this->dispatcher->listen(...)`, where `dispatcher` is a typed property holding a `Dispatcher`, is not matched. Only local-variable receivers are tracked, not property accesses.
- **App array-access key.** The array-access shape matches only the literal `$this->app['events']` form with the `'events'` string key. Other keys or non-`$this->app` array receivers are not matched.
- **Anonymous-class dispatcher wrappers.** A dispatcher wrapped in or returned from an anonymous class expression is dropped.
- **Cross-scope / conditional variable tracking.** Variable tracking is a single flat pass over the file (assignment-precedes-use), so a variable assigned in one method is visible to a `listen()` call in another, and a reassignment inside a branch or loop is handled positionally rather than by control flow. Over-reach is possible but is bounded to over-reporting listeners — never to misattributing them to a wrong event.
- **Nested `$events->subscribe(SubscriberFqcn::class)` inside a `subscribe()` body.** Not matched. Subscriber registrations are only picked up from the `$subscribe` array on `EventServiceProvider` and top-level `Event::subscribe(...)` calls; chaining subscribers from inside another subscriber's body is out of scope.
- **Registrations hidden inside nested closures or other method calls inside `subscribe()`.** Example: `collect([…])->each(fn () => $events->listen(Event::class, …))`. The walker descends into control-flow statements but does not enter nested closures, arrow functions, or method bodies, so these registrations are dropped.
- **Dispatcher accessed inside a `subscribe()` body by anything other than the subscribe parameter.** Within the imperative subscriber walker, `$this->dispatcher->listen(...)`, `app(Dispatcher::class)->listen(...)`, or a re-aliased variable that wasn't the original method parameter are not matched. Only `listen(...)` calls on the parameter that occupies the dispatcher position are routed inside `subscribe()`. (The container-form receivers above apply to top-level `listen()` discovery, not to the subscriber-body walker.)
- **`ShouldQueue` inherited through vendor classes.** The resolver only indexes `app/`. A listener that extends a class from a vendor package which itself implements `ShouldQueue` will report `queued: false`.
- **Traits providing `handle()`.** Auto-discovery only inspects methods declared on the class itself, not those mixed in via traits.
- **Union, intersection, nullable, builtin type-hints on `handle()`.** No auto-discovered event. The listener is still emitted with `handles: []`.
- **Unresolvable callable forms.** `$obj->method(...)` instance first-class callables (no receiver tracking), `'Foo::method'` string callables, and `Closure::fromCallable($var)` with a variable argument resolve to no concrete FQCN+method and are detected by neither this scanner nor [ClosureListenerScanner](closure-listeners.md).
- **Wildcard listeners (`Event::listen('eloquent.*', …)`).** These are model events, not class events. They appear in `model_events[]` via [ObserverScanner](observers.md), not in `listeners[]`.
- **Listener whose FQCN can't be located on disk.** Dropped. The schema requires `file` and `line`.

## When something looks wrong

Triage checklist for missing listeners:

1. Is it registered in `$listen` on an `EventServiceProvider` (or a class named `EventServiceProvider`)? Yes → should be picked up. Check the class-shape filter is satisfied.
2. Is it under `app/Listeners/` with a `handle()` method? Yes → auto-discovered.
3. Is it registered via a `listen(EventClass::class, Listener::class)` call on the dispatcher — the `Event::` facade or a [container-resolved dispatcher](#container-form-registrations)? Yes → picked up regardless of where the calling file lives, as long as both event and listener are `::class` references (not strings or variables). If the dispatcher arrives as a typed `function boot(Dispatcher $dispatcher)` parameter, or via `$this->dispatcher`, it's a [known gap](#known-limitations).
4. Is it a closure or arrow function? It's emitted into `closure_listeners[]`, not `listeners[]`. See [closure-listeners.md](closure-listeners.md).
5. Is the listener file findable via the PSR-4 guess (leading `App\` → `app/`)? Run `ls app/{trimmed path}.php`. If not found, the listener is dropped.

For unexpected `registration` values: trace through the precedence rule. Listeners present in `$listen` AND in `app/Listeners/` get `registration: listen_array` because that path wins.

For unexpected empty `handles[]`: confirm the type-hint on `handle()` is a single class name (not a union, intersection, nullable, or builtin), or that the listener is in `$listen` keyed by an event FQCN.
