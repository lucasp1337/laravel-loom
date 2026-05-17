# Closure Listeners

Discovers closure-based event listener registrations and emits the `closure_listeners[]` section of the index.

Closures and arrow functions cannot be represented in `listeners[]` because they have no FQCN. They get their own top-level section, with one entry per closure registration site.

## What it detects

Three discovery paths:

1. **Closure value inside `$listen` array.** `protected $listen = [OrderPlaced::class => [fn ($e) => …]]` (or a long-form `function ($e) { … }`) on a class named `EventServiceProvider` OR extending `Illuminate\Foundation\Support\Providers\EventServiceProvider`. Emitted with `registration: "listen_array"`.

2. **Closure as the second argument of `Event::listen()`.** `Event::listen(OrderPlaced::class, fn ($e) => …)` anywhere under `app/`. Emitted with `registration: "event_listen_call"`. The class-shape filter that applies to `$listen` walks does NOT apply here — any static call to the `Event` facade qualifies.

3. **Closure in a subscriber's `subscribe()` return-array.** `public function subscribe($events): array { return [OrderPlaced::class => fn ($e) => …]; }` on any class registered as a subscriber (via `$subscribe` array or `Event::subscribe(...)`). Emitted with `registration: "subscriber"`.

Both `Closure` (long-form `function ($e) { … }`) and `ArrowFunction` (`fn ($e) => …`) are detected. The event key may be a `::class` reference or a raw string (`'user.created'`).

## Output

One entry per closure registration site, conforming to `$defs/closure_listener`:

```json
{
  "event": "App\\Events\\OrderPlaced",
  "file": "app/Providers/EventServiceProvider.php",
  "line": 38,
  "registration": "event_listen_call",
  "queued": false,
  "dispatches": []
}
```

- `event` is always a `string`. FQCN for `::class` registrations, raw string for `'user.created'`-style registrations.
- `file` / `line` point to the closure node itself, not the surrounding registration call.
- `registration` enum: `listen_array`, `event_listen_call`, `subscriber`.
- `queued` is always `false`. Closure-queue detection is out of scope.
- `dispatches` is always `[]`. The field is reserved for future line-span-based dispatch attribution.

Entries are sorted by `(file, line, event)` ascending for determinism.

## Expected behavior

- **FQCN event key.** `Event::listen(OrderPlaced::class, fn ($e) => …)` → `event: "App\\Events\\OrderPlaced"`.
- **String event key.** `Event::listen('user.created', fn ($e) => …)` → `event: "user.created"`.
- **Arrow function vs long-form closure.** Both forms are detected and produce identical output. The `event`, `file`, and `line` are recorded against the closure node.
- **Mixed `$listen` arrays.** When `$listen = [OrderPlaced::class => [SendNotifications::class, fn ($e) => …]]`, the class entry flows into `listeners[]` and the closure entry flows into `closure_listeners[]`. Each path emits independently; nothing is dropped.
- **Subscriber return-arrays with mixed values.** `return [OrderPlaced::class => 'handlerMethod', OrderRefunded::class => fn ($e) => …]` contributes the string method to the subscriber's `listeners[*].handles[]` and the closure to `closure_listeners[]`.
- **Multiple closure registrations against the same event.** Each registration site is its own entry. No dedupe by `event`.

## Known limitations

- **`queued` always `false`.** Closure-queue detection (e.g. wrapping in `Queue::push(...)` or chaining `->onQueue(...)`) is not implemented. Treat the field as a stable placeholder.
- **`dispatches` always `[]`.** Reserved field. Line-span-based attribution of dispatch sites inside a closure body into `closure_listeners[*].dispatches` is planned for a follow-up release.
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
