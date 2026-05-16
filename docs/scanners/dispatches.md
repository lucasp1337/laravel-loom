# DispatchScanner

Identifies dispatch sites (event and job dispatches) throughout the codebase, emits `unresolved_dispatches[]` directly, and feeds the cross-link pass that populates `listeners[*].dispatches`, `observers[*].dispatches`, and `events[*].dispatched_from`.

This is the most cross-cutting scanner. It runs last (after EventScanner, ListenerScanner, and ObserverScanner) so the cross-link pass has access to every primitive's data.

## What it detects

DispatchScanner walks every PHP file under `app/` and records dispatch sites in any class method body. Recognized forms:

- `event(new SomeEvent(...))` and `event(SomeEvent::class)` — `kind: event`, `form: helper`
- `Event::dispatch(new SomeEvent(...))` and `Event::dispatch(SomeEvent::class)` — `kind: event`, `form: facade`
- `dispatch(new SomeJob(...))` and `dispatch(SomeJob::class)` — `kind: job`, `form: job_helper`
- `Bus::dispatch(new SomeJob(...))` — `kind: job`, `form: bus_facade`
- `SomeClass::dispatch(...)` Dispatchable trait — `kind: ambiguous` at the visitor level, finalized by the cross-link pass against `events[]`

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

**`listeners[*].dispatches`** — entries whose `classFqcn` matches a listener AND `method === 'handle'`. Built as `$defs/dispatch`:

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

## Kind classification

The cross-link pass disambiguates `kind: ambiguous` (Dispatchable form) before populating the other fields:

1. If `target` is in `events[]` (built by EventScanner), `kind = event`
2. Otherwise, `kind = job`

EventScanner's dispatch-site seeding ensures most Dispatchable-form events are already in `events[]`. Classes that aren't (jobs in `app/Jobs/`, internal classes that happen to use the Dispatchable trait) fall through to `job`.

## Confidence

`confidence` is always `"high"` for statically resolved targets. The `"medium"` and `"low"` enum values are reserved for future runtime overlay work.

## Expected behavior

- **Dispatch inside `handle()` on a listener.** Contributes to that listener's `dispatches[]`.
- **Dispatch inside an Eloquent hook method on an observer.** Contributes to that observer's `dispatches[]`.
- **Dispatch in any other method.** Doesn't appear in `listeners[*].dispatches` or `observers[*].dispatches`. May still contribute to `events[*].dispatched_from` if it dispatches an event.
- **Dispatch inside a non-handler method on a listener** (e.g. `SendOrderConfirmation::otherMethod()`). The site is recorded in `_dispatch_sites` but doesn't appear in `listeners[*].dispatches` (because `method !== 'handle'`). If it dispatches an event, it does contribute to `events[*].dispatched_from`.
- **Multiple dispatches in one method.** Each recorded separately.
- **Two ternary branches both resolving to concrete classes.** Two resolved sites emitted, one per branch. Not `conditional_dispatch`.
- **Dispatchable form `X::dispatch(...)` where `X` is in `events[]`.** Finalized as `kind: event`.
- **Dispatchable form `X::dispatch(...)` where `X` is in `app/Jobs/`.** Finalized as `kind: job` (since EventScanner's seeding rejects it under the `app/Events/` trim).
- **Top-level dispatch outside any class.** Skipped. No enclosing class context.
- **Dispatch inside a closure** (assigned to a variable, passed to a function, returned from a method). Skipped. Too easy to over-report — the closure may never execute, may execute in a different context, etc.
- **Dispatch inside an arrow function.** Skipped (closure depth counter).

## Known limitations

- **`dispatch_sync`, `dispatch_now`, `Bus::dispatchSync`, `Bus::dispatchNow`.** Not recognized. Synchronous dispatches are a niche case.
- **`Queue::push(...)`, `Queue::later(...)`.** Not recognized.
- **Trait-method dispatches.** Sites in trait methods record the trait's FQCN as `classFqcn`. The trait FQCN won't match any listener or observer entry, so the dispatch won't light up `dispatches[]` — but it can still populate `events[*].dispatched_from` if its target is a known event.
- **Top-level dispatches (script-level code).** Skipped entirely.
- **Closures of any kind.** Skipped. This is by design but means dispatches inside collection callbacks (`->each(fn () => event(...))`) are invisible to Atlas.
- **Multi-handler listeners.** Only literal `method === 'handle'` matches for listener `dispatches[]`. A listener with `handleFoo()` and `handleBar()` doesn't have those methods' dispatches recorded under it.
- **Dispatch sites inside abstract methods.** Won't happen (abstract methods have no body), but worth noting that interface-declared dispatch contracts aren't introspected.
- **Cross-link orphans.** A dispatch site whose `target` doesn't match any event in `events[]` doesn't contribute to `dispatched_from`. This usually means EventScanner didn't discover the target (event class outside `app/Events/` and not dispatched via the helper/facade forms). Not a DispatchScanner bug — fix by ensuring the target is discoverable.
- **Confidence is uniformly `"high"`.** No medium/low classification yet.

## When something looks wrong

Triage checklist for a missing dispatch entry:

1. Is the dispatch inside a closure? That's the documented skip.
2. Is the dispatch in a top-level script (not inside a class)? Skipped.
3. Is the enclosing method literally named `handle` (for listeners) or a canonical Eloquent hook (for observers)? If not, the site won't appear in `dispatches[]`. Check `_dispatch_sites` (visible only inside cross-link, but observable indirectly via `events[*].dispatched_from`).
4. Is the dispatched target resolvable? `event($variable)` should appear in `unresolved_dispatches[]` instead.
5. For Dispatchable-form `X::dispatch(...)`: did EventScanner find `X`? If not, it's classified as a job. Check `events[]`.

For unexpected entries in `unresolved_dispatches[]`: trace the dispatch site at the reported `file:line`. The four reason codes map directly to the AST shapes documented above.

For unexpected `kind` on Dispatchable-form sites: check whether the target FQCN appears in `events[]`. If you want it classified as an event, ensure EventScanner discovers it (move to `app/Events/`, or dispatch it elsewhere via `event(new X())` to seed it).
