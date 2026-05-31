# Closure Listeners

Discovers closure-based event listener registrations and emits the `closure_listeners[]` section of the index.

Closures and arrow functions cannot be represented in `listeners[]` because they have no FQCN. They get their own top-level section, with one entry per closure registration site.

## What it detects

Three discovery paths:

1. **Closure value inside `$listen` array.** `protected $listen = [OrderPlaced::class => [fn ($e) => …]]` (or a long-form `function ($e) { … }`) on a class named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. Emitted with `registration: "listen_array"`.

2. **Closure as the second argument of `Event::listen()`.** `Event::listen(OrderPlaced::class, fn ($e) => …)` anywhere under `app/`. Emitted with `registration: "event_listen_call"`. The class-shape filter that applies to `$listen` walks does NOT apply here — any static call to the `Event` facade qualifies.

3. **Closure inside a subscriber's `subscribe()` body** — either as a return-array value (`return [OrderPlaced::class => fn ($e) => …]`) or as the second argument to an imperative `$events->listen(OrderPlaced::class, fn ($e) => …)` call against the dispatcher parameter. Applies to any class registered as a subscriber (via `$subscribe` array or `Event::subscribe(...)`). Both sub-cases emit with `registration: "subscriber"`.

Both `Closure` (long-form `function ($e) { … }`) and `ArrowFunction` (`fn ($e) => …`) are detected. The event key may be a `::class` reference or a raw string (`'user.created'`).

## Output

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

## `dispatches`

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

## Expected behavior

- **FQCN event key.** `Event::listen(OrderPlaced::class, fn ($e) => …)` → `event: "App\\Events\\OrderPlaced"`.
- **String event key.** `Event::listen('user.created', fn ($e) => …)` → `event: "user.created"`.
- **Arrow function vs long-form closure.** Both forms are detected and produce identical output. The `event`, `file`, and `line` are recorded against the closure node.
- **Mixed `$listen` arrays.** When `$listen = [OrderPlaced::class => [SendNotifications::class, fn ($e) => …]]`, the class entry flows into `listeners[]` and the closure entry flows into `closure_listeners[]`. Each path emits independently; nothing is dropped.
- **Subscriber return-arrays with mixed values.** `return [OrderPlaced::class => 'handlerMethod', OrderRefunded::class => fn ($e) => …]` contributes the string method to the subscriber's `listeners[*].handles[]` and the closure to `closure_listeners[]`.
- **Multiple closure registrations against the same event.** Each registration site is its own entry. No dedupe by `event`.
- **Dispatches inside the closure body.** A resolved `event(new Foo)` / `dispatch(new Bar)` inside the closure populates that entry's `dispatches[]`. An empty (or dispatch-free) closure keeps `dispatches: []`.
- **No reverse edge.** A dispatch made inside a closure listener appears in that closure's `dispatches[]`, but the target event's or job's reverse `dispatched_from[]` does **not** list the closure as a source. This mirrors the `events[*].handled_by` design: closures have no nameable identity to record on the other side of the edge. Consumers asking "where is `Foo` dispatched from?" won't see closure listeners in `dispatched_from`.
- **Nested closures.** A dispatch sitting inside a closure nested within the listener closure is attributed to every enclosing closure-listener span that contains it. Overlapping spans only arise through nesting.

## Known limitations

- **`queued` always `false`.** Closure-queue detection (e.g. wrapping in `Queue::push(...)` or chaining `->onQueue(...)`) is not implemented. Treat the field as a stable placeholder.
- **Unresolved dispatches inside a closure are dropped.** Only statically-resolved dispatches land in `dispatches[]`. A dynamic target inside a closure body (`event($var)`, container-resolved, string-concatenated) is captured neither in `dispatches[]` nor in `unresolved_dispatches[]`.
- **`Closure::fromCallable()` / first-class callable syntax (`$obj->method(...)`).** Not detected. Only literal `Closure` and `ArrowFunction` nodes in the registration position qualify.
- **Container-form registrations.** `$this->app['events']->listen(Event::class, fn ($e) => …)`, `app(Dispatcher::class)->listen(...)`, `resolve(Dispatcher::class)->listen(...)` are not matched. Only the `Event::` facade form is recognized.
- **Dynamic event names.** `Event::listen($variable, fn ($e) => …)` is skipped. There's no resolvable event key to record.
- **`events[*].handled_by` does not link back to closure listeners.** That field's entries are `{listener: string, method: string}` pairs; closures have neither an FQCN nor a method name. Consumers answering "what handles `Foo`?" should also filter `closure_listeners[]` by the `event` field.

## When something looks wrong

Triage checklist for missing closure listeners:

1. Is the registration on a class named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`? (Applies only to `$listen` array form.) If not, the `$listen` walk skips it.
2. Is the call shape `Event::listen(EventName, Closure)` against the facade — not `$dispatcher->listen(...)` or container-resolved? Only the facade static-call form is matched.
3. Is the event key a `::class` reference or a quoted string literal? Variables are dropped.
4. Is the value a literal `fn (...) => ...` or `function (...) { ... }` — not `Closure::fromCallable(...)` or `$obj->method(...)`? Only literal closure / arrow-function nodes qualify.
5. For subscribers: is the subscriber itself discovered (check `listeners[]` for an entry with `registration: "subscriber"`)? If the subscriber registration is missed, no closures from its `subscribe()` body will land either.

For unexpected `event` values that are strings rather than FQCNs: that's by design — the scanner records what the source actually wrote. Loom does not attempt to resolve event aliases against runtime broadcast configuration.
