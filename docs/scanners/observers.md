# ObserverScanner

Discovers Eloquent observers and emits both `observers[]` and `model_events[]` sections of the index.

This is the only scanner that emits to two top-level schema sections. Both come from the same discovery walk; the dual emission is bundled here because the data is the same.

## What it detects

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

## Output

### `observers[]` entries (`$defs/observer`)

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

### `model_events[]` entries (`$defs/modelEvent`)

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

### Canonical hook enum

The hook names recognized in both `hooks[]` (observer methods) and the `model_events.event` field:

```
retrieved, creating, created, updating, updated, saving, saved,
deleting, deleted, restoring, restored, replicating, trashed,
forceDeleting, forceDeleted, booting, booted
```

## Expected behavior

- **Observer registered via both attribute and `Model::observe()`.** Single entry per `(observer, model)` pair with `registration: attribute`.
- **`static::observe(UserObserver::class)` inside `User::booted()`.** Model resolves to `User` via the enclosing class context.
- **Observer registered against multiple models.** Multiple entries (one per model). The schema's single-string `observes` constraint requires this.
- **Observer with no hook methods that match the canonical enum.** Still emitted with `hooks: []`. The registration exists; the hook list is empty.
- **Observer class with mixed-visibility hook methods.** All hooks are collected regardless of visibility (public, protected, private).
- **`#[ObservedBy([A::class, B::class])]`.** Produces two observer entries.
- **Model_events dedupe.** Observer hooks AND `Event::listen('eloquent.created: User', UserObserver::class . '@created')` referring to the same observer hook contribute the same `"UserObserver::created"` string — deduplicated to a single entry in `handled_by`.
- **Path 3 with non-observer handler.** `Event::listen('eloquent.deleted: App\Models\Product', 'App\Handlers\InvoiceHandler@deleted')` produces a `model_events` entry with `InvoiceHandler::deleted` in `handled_by` but does NOT create an observer entry for `InvoiceHandler`.

## Known limitations

- **Closure handlers in `Event::listen('eloquent.*', fn ...)`.** Silently dropped. No FQCN to record.
- **Dynamic args.** `#[ObservedBy($var)]`, `Model::observe($var)`, `$class::observe(...)`, `Event::listen($var, ...)` are all skipped. Loom does not currently emit unresolved entries for observer registrations.
- **Container-form registrations.** `app('events')->listen('eloquent.*', ...)` is not matched. Only the `Event::` facade form for path 3.
- **Inherited hooks from parent observer classes.** Not resolved. The hook visitor only sees methods declared on the class itself.
- **Trait-provided hook methods.** Not resolved. Same reason.
- **Observer registered only via `Event::listen('eloquent.*', ...)`.** Will NOT appear in `observers[]`. It does appear in `model_events[*].handled_by`. This is intentional — path 3 doesn't imply the handler is an observer-shaped class.
- **`parent::observe(...)`.** Skipped. The parent class FQCN is technically recoverable, but the intent of `parent::observe(...)` is ambiguous (usually the developer means `static::`).
- **`$this->observe(...)`.** Skipped. Not a standard Laravel pattern.
- **Observer FQCN that can't be located on disk.** Dropped. The schema requires `file` and `line`.
- **`Event::listen` with invalid hook name.** Skipped. Strings like `'eloquent.invalid: User'` produce no entry.

## When something looks wrong

Triage checklist for missing observers:

1. Is the model decorated with `#[ObservedBy(Observer::class)]`? Yes → `registration: attribute`.
2. Is there a `Model::observe(Observer::class)` call somewhere under `app/`? Yes → `registration: observe_call`. Check `static::observe(...)` resolves to the enclosing class.
3. Is the observer class file findable via PSR-4 (or already in `app/Observers/`)? If neither, the entry is dropped.
4. Does the observer have hook methods matching the canonical enum? If not, the entry still appears with `hooks: []`.

Triage checklist for missing `model_events` entries:

1. Is there at least one observer with a hook method matching the canonical enum for that `(model, hook)` pair? Or a path-3 `Event::listen` string?
2. If neither, no entry is emitted by design.
3. If the entry exists but `handled_by` is missing an expected handler: check that the observer's hook method name matches exactly (case-sensitive), and that the observer's `observes` matches the model FQCN.
