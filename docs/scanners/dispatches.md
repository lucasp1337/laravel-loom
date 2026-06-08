# DispatchScanner

Identifies dispatch sites (event and job dispatches) throughout the codebase, emits `unresolved_dispatches[]` directly, and feeds the cross-link pass that populates `listeners[*].dispatches`, `observers[*].dispatches`, and `events[*].dispatched_from`.

This is the most cross-cutting scanner. It runs last (after EventScanner, ListenerScanner, and ObserverScanner) so the cross-link pass has access to every primitive's data.

## What it detects

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

## Output

### Direct: `unresolved_dispatches[]` (`$defs/unresolvedDispatch`)

```json
{
  "file": "app/Services/Notifier.php",
  "line": 42,
  "expression": "event($eventClass)",
  "reason": "dynamic_class_name"
}
```

Sorted by `(file, line)` for determinism.

### Internal: `_dispatch_sites[]`

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

### Cross-link populates these from `_dispatch_sites`

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

## Dispatch-time modifiers (`overrides`)

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

## Channel filter (`channels`, notifications only)

`$defs/dispatchSite` carries one further optional field — `channels` — emitted only on `notifications[*].notified_from` entries. It records the literal channel filter passed as the third argument to `Notification::send($users, $notification, $channels)` / `Notification::sendNow(...)`, which restricts that dispatch to a specific channel set and overrides the notification's own `via()`. Values use the same representation as `notifications[*].channels`: literal string channel names stored lowercased, `Class::class` channel constants stored as FQCN, in source order.

```json
{
  "file": "app/Services/Billing.php",
  "line": 88,
  "method": "App\\Services\\Billing::charge",
  "channels": ["mail", "App\\Channels\\SlackChannel"]
}
```

The key is omitted when the argument is absent, empty, or non-literal. It is captured only on the `Notification::send` / `Notification::sendNow` facade forms — the `->notify(...)` method form has no channel-filter argument, and the other three reverse-reference arrays (`events[*].dispatched_from`, `jobs[*].dispatched_from`, `mailables[*].sent_from`) never carry it. Full semantics live in [docs/scanners/notifications.md](notifications.md).

## Kind classification

The cross-link pass disambiguates `kind: ambiguous` (Dispatchable form) before populating the other fields:

1. If `target` is in `events[]` (built by EventScanner), `kind = event`
2. Otherwise, `kind = job`

EventScanner's dispatch-site seeding ensures most Dispatchable-form events are already in `events[]`. Classes that aren't (jobs in `app/Jobs/`, internal classes that happen to use the Dispatchable trait) fall through to `job`.

## Confidence

`confidence` is always `"high"` for statically resolved targets. The `"medium"` and `"low"` enum values are reserved for future runtime overlay work.

## Expected behavior

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

## Known limitations

- **`dispatchSync`, `dispatchNow`, `dispatchAfterResponse`, `Bus::dispatchSync`, `Bus::dispatchNow`.** Not recognized. Synchronous and after-response dispatches are intentionally out of scope.
- **`Bus::chain([...])`, `Bus::batch([...])`.** The chained/batched job array contents are not enumerated. The dispatch call itself is not recorded and the inner jobs do not surface in `jobs[*].dispatched_from`.
- **Container-form dispatch.** `app(Dispatcher::class)->dispatch(...)` (a dispatcher resolved from the container) is unresolved — only the `event()` / `Event::dispatch()` / `dispatch()` / `Bus::dispatch()` / Dispatchable forms above are recognised.
- **`ShouldBroadcast` marker interface.** Not surfaced as a flag. The `broadcast(...)` / `broadcast_if(...)` / `broadcast_unless(...)` *dispatch* forms ARE recognised as event dispatches (see "What it detects"), but the `ShouldBroadcast` contract on an event class itself is not introspected.
- **`Queue::push(...)`, `Queue::later(...)`.** Not recognized.
- **Trait-method dispatches.** Sites in trait methods record the trait's FQCN as `classFqcn`. The trait FQCN won't match any listener or observer entry, so the dispatch won't light up `dispatches[]` — but it can still populate `events[*].dispatched_from` if its target is a known event.
- **Top-level dispatches (script-level code).** Skipped entirely.
- **Closures of any kind.** Skipped. This is by design but means dispatches inside collection callbacks (`->each(fn () => event(...))`) are invisible to Loom.
- **Dispatches inside helper methods called from a handler.** A listener whose `handle()` calls `$this->doWork()`, where `doWork()` is the one that dispatches — the dispatch site is recorded in `_dispatch_sites` but isn't attributed to the listener (because `doWork` isn't in `handles[*].method`). It still contributes to `events[*].dispatched_from` if its target is an event.
- **Dispatch sites inside abstract methods.** Won't happen (abstract methods have no body), but worth noting that interface-declared dispatch contracts aren't introspected.
- **Cross-link orphans.** A dispatch site whose `target` doesn't match any event in `events[]` doesn't contribute to `dispatched_from`. This usually means EventScanner didn't discover the target (event class outside `app/Events/` and not dispatched via the helper/facade forms). Not a DispatchScanner bug — fix by ensuring the target is discoverable.
- **`->delay()` with a non-integer-literal argument.** Only integer-second literals (`->delay(60)`) are captured into `overrides.delay`. `->delay(now()->addMinutes(5))` and `->delay($seconds)` are not captured — the `delay` key is absent.
- **Modifiers set on a separate statement.** `$job->onQueue('high'); dispatch($job);` is out of static reach — the modifiers and the dispatch are different statements over a variable, and `overrides` records only the chain at the dispatch site itself.
- **Output handlers and conditional closures.** `->ping*()` output handlers and the closures in `->when()` / `->skip()` are out of scope (per #32) and never enter `overrides`.
- **Variable receivers in a chain.** `$job->onQueue('high')` where `$job` is a variable doesn't resolve a target. Only `new X` / `X::class` receivers resolve through the chain; a variable at the base is unresolvable like any other dynamic class name.
- **Confidence is uniformly `"high"`.** No medium/low classification yet.

## When something looks wrong

Triage checklist for a missing dispatch entry:

1. Is the dispatch inside a closure? That's the documented skip.
2. Is the dispatch in a top-level script (not inside a class)? Skipped.
3. For a listener: is the enclosing method registered as a handler — i.e. does it appear in that listener's `handles[*].method` set? Auto-discovered listeners only have `handle`; multi-handler listeners may have `handleFoo`, `handleBar`, etc. Helper methods called from a registered handler don't count. For an observer: is the enclosing method a canonical Eloquent hook name? If neither matches, the site won't appear in `dispatches[]`, though it may still contribute to `events[*].dispatched_from`.
4. Is the dispatched target resolvable? `event($variable)` should appear in `unresolved_dispatches[]` instead.
5. For Dispatchable-form `X::dispatch(...)`: did EventScanner find `X`? If not, it's classified as a job. Check `events[]`.

For unexpected entries in `unresolved_dispatches[]`: trace the dispatch site at the reported `file:line`. The four reason codes map directly to the AST shapes documented above.

For unexpected `kind` on Dispatchable-form sites: check whether the target FQCN appears in `events[]`. If you want it classified as an event, ensure EventScanner discovers it (move to `app/Events/`, or dispatch it elsewhere via `event(new X())` to seed it).
