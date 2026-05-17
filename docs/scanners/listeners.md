# ListenerScanner

Discovers event listeners and emits the `listeners[]` section of the index.

> Closure-based listeners (arrow functions and `function () { … }` values in `$listen`, `Event::listen()`, or subscriber return-arrays) are emitted into the separate `closure_listeners[]` section instead — they have no FQCN to key against the `listeners[]` shape. See [closure-listeners.md](closure-listeners.md).

## What it detects

ListenerScanner uses four discovery paths and merges them by listener FQCN:

1. **Auto-discovery via `app/Listeners/`.** Every class in `app/Listeners/` with a public `handle()` method is a listener candidate. The first parameter's type-hint becomes the event the listener handles. Classes implementing `Illuminate\Contracts\Queue\ShouldQueue` directly are marked `queued: true`.

2. **`$listen` array on `EventServiceProvider`.** Walks the entire `app/` tree (not just `app/Providers/`) and looks at classes named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. The `$listen` property (any visibility, must be `array`) is parsed: each `EventClass::class => [Listener::class, …]` pair becomes a registration. Bare `Listener::class` values map to `method: "handle"`. Tuple values `[Listener::class, 'method']` preserve the method name.

3. **`Event::listen()` static calls.** Walks the entire `app/` tree for `\Illuminate\Support\Facades\Event::listen(EventClass::class, Listener::class)` calls. Bare `Listener::class` second arguments map to `method: "handle"`; tuple form `[Listener::class, 'method']` preserves the method name. The class-shape filter only applies in path 2 — Event::listen calls are scanner-agnostic about the surrounding class, so providers in DDD-style layouts (e.g. `app/Domain/Invoicing/Providers/InvoicingServiceProvider.php`) are discovered.

4. **Subscribers.** Classes registered via the `$subscribe = [SubscriberClass::class, …]` array on an `EventServiceProvider`, or via `Event::subscribe(SubscriberClass::class)` calls. The subscriber's own `subscribe($events): array` method is then parsed: the return-array form contributes events to the subscriber's `handles[]`. Both `[Event::class => 'handlerMethod']` and tuple values `[Event::class => [self::class, 'handlerMethod']]` / `[Event::class => [Subscriber::class, 'handlerMethod']]` are recognised. Subscribers receive `registration: "subscriber"` — the highest-precedence source.

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
- **`Event::listen()` inside a non-provider class.** Discovered. The `Event::listen` visitor doesn't filter by surrounding class shape — it accepts any static call.
- **`Event::listen(\Illuminate\Support\Facades\Event::class, ...)` fully qualified.** Resolved correctly via NameResolver.
- **`ShouldQueue` implemented directly.** `queued: true`. The check is on the class's `implements` clause post-NameResolver.

## Known limitations

- **Dynamic event names.** `Event::listen($variable, Listener::class)` is skipped.
- **Container-form registrations.** `$this->app['events']->listen(...)`, `app(Dispatcher::class)->listen(...)`, `resolve(Dispatcher::class)->listen(...)` are not matched. Only the `Event::` facade form is recognized.
- **Subscribers — imperative `subscribe()` form.** Only the return-array form (`return [Event::class => 'method', …]`) is parsed. Bodies that call `$events->listen(Event::class, [self::class, 'method'])` imperatively contribute no events to `handles[]` — the subscriber is still emitted, just with an empty `handles` array.
- **Indirect `ShouldQueue` via parent class.** `queued` reports `false` if the listener inherits `ShouldQueue` rather than implementing it directly.
- **Traits providing `handle()`.** Auto-discovery only inspects methods declared on the class itself, not those mixed in via traits.
- **Union, intersection, nullable, builtin type-hints on `handle()`.** No auto-discovered event. The listener is still emitted with `handles: []`.
- **Wildcard listeners (`Event::listen('eloquent.*', …)`).** These are model events, not class events. They appear in `model_events[]` via [ObserverScanner](observers.md), not in `listeners[]`.
- **Listener whose FQCN can't be located on disk.** Dropped. The schema requires `file` and `line`.

## When something looks wrong

Triage checklist for missing listeners:

1. Is it registered in `$listen` on an `EventServiceProvider` (or a class named `EventServiceProvider`)? Yes → should be picked up. Check the class-shape filter is satisfied.
2. Is it under `app/Listeners/` with a `handle()` method? Yes → auto-discovered.
3. Is it registered via `Event::listen(EventClass::class, Listener::class)`? Yes → picked up regardless of where the calling file lives, as long as both event and listener are `::class` references (not strings or variables).
4. Is it a closure or arrow function? It's emitted into `closure_listeners[]`, not `listeners[]`. See [closure-listeners.md](closure-listeners.md).
5. Is the listener file findable via the PSR-4 guess (leading `App\` → `app/`)? Run `ls app/{trimmed path}.php`. If not found, the listener is dropped.

For unexpected `registration` values: trace through the precedence rule. Listeners present in `$listen` AND in `app/Listeners/` get `registration: listen_array` because that path wins.

For unexpected empty `handles[]`: confirm the type-hint on `handle()` is a single class name (not a union, intersection, nullable, or builtin), or that the listener is in `$listen` keyed by an event FQCN.
