# ListenerScanner

Discovers event listeners and emits the `listeners[]` section of the index.

## What it detects

ListenerScanner uses three discovery paths and merges them by listener FQCN:

1. **Auto-discovery via `app/Listeners/`.** Every class in `app/Listeners/` with a public `handle()` method is a listener candidate. The first parameter's type-hint becomes the event the listener handles. Classes implementing `Illuminate\Contracts\Queue\ShouldQueue` directly are marked `queued: true`.

2. **`$listen` array on `EventServiceProvider`.** Walks the entire `app/` tree (not just `app/Providers/`) and looks at classes named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. The `$listen` property (any visibility, must be `array`) is parsed: each `EventClass::class => [Listener::class, …]` pair becomes a registration. Tuple values `[Listener::class, 'method']` record the listener FQCN; the method name is discarded.

3. **`Event::listen()` static calls.** Walks the entire `app/` tree for `\Illuminate\Support\Facades\Event::listen(EventClass::class, Listener::class)` calls. The class-shape filter only applies in path 2 — Event::listen calls are scanner-agnostic about the surrounding class, so providers in DDD-style layouts (e.g. `app/Domain/Invoicing/Providers/InvoicingServiceProvider.php`) are discovered.

## Output

One entry per listener FQCN, conforming to `$defs/listener`:

```json
{
  "fqcn": "App\\Listeners\\SendOrderConfirmation",
  "file": "app/Listeners/SendOrderConfirmation.php",
  "line": 14,
  "handles": ["App\\Events\\OrderPlaced"],
  "registration": "listen_array",
  "queued": true,
  "dispatches": []
}
```

`dispatches` is always emitted as an empty array. It is populated by the cross-link pass from DispatchScanner's per-call-site data.

`registration` is set per the precedence rule: `listen_array > event_listen_call > auto_discovered`. When a listener is discovered through multiple paths, the entry's `registration` reports the highest-precedence source observed.

`handles` is the union of event FQCNs across all paths, sorted ascending.

Entries are sorted by `fqcn` ascending.

## Expected behavior

- **Listener registered via multiple paths.** Single entry. `handles` is the union; `registration` is the highest-precedence source.
- **Listener with typed `handle(OrderPlaced $event)`.** Auto-discovered. `handles: ['App\\Events\\OrderPlaced']` (or whatever the resolved type-hint is).
- **Listener with `handle($event)` (no type-hint).** Auto-discovered with `handles: []`. The listener is still registered; it just doesn't auto-discover a target event. Other paths may still add events.
- **Listener listed in `$listen` but located outside `app/Listeners/`.** Picked up via the PSR-4 guess (leading `App\` → `app/`). If the file can't be located on disk, the listener is dropped (the schema requires `file` and `line`).
- **`$listen` tuple form `[Listener::class, 'method']`.** Listener FQCN is recorded; the method name is currently discarded. Multi-handler listeners (`handleFoo`, `handleBar`) are not represented.
- **`Event::listen()` inside a non-provider class.** Discovered. The `Event::listen` visitor doesn't filter by surrounding class shape — it accepts any static call.
- **`Event::listen(\Illuminate\Support\Facades\Event::class, ...)` fully qualified.** Resolved correctly via NameResolver.
- **`ShouldQueue` implemented directly.** `queued: true`. The check is on the class's `implements` clause post-NameResolver.

## Known limitations

- **Closures / arrow-function listeners.** `Event::listen(EventClass::class, fn ($e) => …)` and closure values inside `$listen` are silently dropped. There's no FQCN to record. Loom does NOT currently emit an `unresolved_dispatches`-style entry for unresolvable listener registrations.
- **Dynamic event names.** `Event::listen($variable, Listener::class)` is skipped.
- **Tuple method names.** `[Listener::class, 'customHandler']` records the listener but loses the method name. Multi-handler listeners aren't represented.
- **Container-form registrations.** `$this->app['events']->listen(...)`, `app(Dispatcher::class)->listen(...)`, `resolve(Dispatcher::class)->listen(...)` are not matched. Only the `Event::` facade form is recognized.
- **Subscribers (`subscribe()` method).** Not detected. The schema enum reserves `registration: "subscriber"` but the scanner doesn't currently emit it.
- **Indirect `ShouldQueue` via parent class.** `queued` reports `false` if the listener inherits `ShouldQueue` rather than implementing it directly.
- **Traits providing `handle()`.** Auto-discovery only inspects methods declared on the class itself, not those mixed in via traits.
- **Union, intersection, nullable, builtin type-hints on `handle()`.** No auto-discovered event. The listener is still emitted with `handles: []`.
- **Wildcard listeners (`Event::listen('eloquent.*', …)`).** These are model events, not class events. They appear in `model_events[]` via [ObserverScanner](observers.md), not in `listeners[]`.
- **Listener whose FQCN can't be located on disk.** Dropped. The schema requires `file` and `line`.

## When something looks wrong

Triage checklist for missing listeners:

1. Is it registered in `$listen` on an `EventServiceProvider` (or a class named `EventServiceProvider`)? Yes → should be picked up. Check the class-shape filter is satisfied.
2. Is it under `app/Listeners/` with a `handle()` method? Yes → auto-discovered.
3. Is it registered via `Event::listen(EventClass::class, Listener::class)`? Yes → picked up regardless of where the calling file lives, as long as both event and listener are `::class` references (not strings, not variables, not closures).
4. Is it a closure listener? That's documented above — not supported.
5. Is the listener file findable via the PSR-4 guess (leading `App\` → `app/`)? Run `ls app/{trimmed path}.php`. If not found, the listener is dropped.

For unexpected `registration` values: trace through the precedence rule. Listeners present in `$listen` AND in `app/Listeners/` get `registration: listen_array` because that path wins.

For unexpected empty `handles[]`: confirm the type-hint on `handle()` is a single class name (not a union, intersection, nullable, or builtin), or that the listener is in `$listen` keyed by an event FQCN.
