# Architecture

How Loom is wired internally.

## Pipeline

```
Filesystem → Discovery → AST parsing → Emission → Merge → Cross-link → Strip internals → Validate → Write
              (per scanner)            (per scanner) (IndexBuilder)             (schema)    (index.json)
```

`IndexBuilder` orchestrates the pipeline. Each scanner contributes to one or more sections of the index, the cross-link pass joins data across scanners, then the result is validated against `schema/loom-index.schema.json` and written to `storage/loom/index.json`.

The `loom:scan` artisan command is a thin wrapper around `IndexBuilder::build()`. The `loom:show` command reads the written index and prints it (optionally filtered by FQCN substring).

## The Scanner contract

```php
namespace Lucasp\Loom\Contracts;

interface Scanner
{
    /**
     * @return array<string, array<int, array<string, mixed>>> Entries keyed by schema section.
     */
    public function scan(string $appRoot): array;
}
```

A scanner takes a path to a Laravel app root and returns an array keyed by section name. Valid section keys are the top-level array properties of the JSON schema:

- `events`
- `listeners`
- `closure_listeners`
- `jobs`
- `observers`
- `model_events`
- `unresolved_dispatches`

Plus one internal key:

- `_dispatch_sites` — underscore-prefixed, used by `DispatchScanner` to ferry per-call-site data into the cross-link pass. Stripped before schema validation.

Scanners are **stateless**. They take a path, return data, no service injection, no Laravel runtime hooks.

A scanner contributing nothing to a section simply omits that key from its return value. Multiple scanners contributing to the same section have their entries merged.

## Three concerns per scanner

Every scanner is internally organized into three concerns. Mixing them produces untestable code.

### 1. Discovery

Find candidate files or classes. Strategies:

- **Filesystem walk** — `RecursiveIteratorIterator` over `app/Events/`, `app/Listeners/`, or all of `app/`. Filter `*.php`.
- **Provider reflection** — parse classes that look like service providers, extract `$listen` arrays or `Event::listen()` calls.
- **Attribute scan** — find classes carrying `#[ObservedBy]`.

Hybrid strategies are common (events come from both `app/Events/` and dispatch-site seeding, for example).

### 2. Parsing

Pure AST work via `nikic/php-parser`. Use `Lucasp\Loom\Support\AstWalker` — it instantiates a `Parser` once and always attaches `NameResolver` before user visitors, so every `Node\Name` your visitor sees is fully qualified.

When a scanner needs transitive `extends` / `implements` / `use Trait` information (e.g. recognising a job as queued via `ShouldQueue` declared on an abstract parent), use `Lucasp\Loom\Support\ClassHierarchyResolver`. It lives at `src/Support/ClassHierarchyResolver.php` and is constructed once per `IndexBuilder::build()` call against the scanned `$appRoot`. It performs a single lazy walk of `app/` on first use, parses each file via the shared `AstWalker` + `ClassDeclarationVisitor`, and memoises queries. External / vendor classes are opaque leaves: traversal records them and stops. See [docs/design/class-hierarchy-resolver.md](design/class-hierarchy-resolver.md) for the full contract.

Visitor conventions:

- **Read on `leaveNode`, not `enterNode`.** NameResolver rewrites child Names as it descends; by the time you `leaveNode` on a `FuncCall` or `StaticCall`, every inner `New_->class` / `ClassConstFetch->class` has been resolved. The one exception is reading `$node->namespacedName` on the class itself — that's set on enter.
- **Reset state in `beforeTraverse()`.** Scanners reuse a single visitor instance across all files in the discovery loop.
- **Expose state via a getter**, not by mutating an external array. Visitors collect into an instance property; the scanner reads it after each `walk()` call.

### 3. Emission

Build schema-shaped arrays. No JSON encoding (that's `IndexBuilder`'s job). No format work. Return PHP arrays and let the caller handle serialization.

Emit deterministically: sort entries by FQCN (or whatever the natural key is) so the output is stable across runs and machines. Inside each entry, sort scalar arrays (`hooks`, `handles`, `handled_by`) ascending.

## IndexBuilder

`IndexBuilder` is the orchestrator. The pipeline in `build()`:

1. **Instantiate** registered scanners (`ScanCommand` registers all four in order)
2. **Run** each scanner against `$appRoot`, in registration order
3. **Merge** returned sections into a single map. Each section is a concatenation of every scanner's contribution.
4. **Cross-link** (see below)
5. **Strip** any `_*` underscore-prefixed sections (internal)
6. **Validate** the merged sections against `schema/loom-index.schema.json` using `justinrainbow/json-schema`
7. **Wrap** the result in an `Index` value object

Validation failure is fatal. A non-conforming index throws rather than writing garbage to disk.

## Cross-link pass

The cross-link pass is the only place that reads cross-scanner data. It runs after every scanner has emitted, so it can rely on having a complete view of events, listeners, observers, and dispatch sites.

Five phases, in order:

1. **`events[*].handled_by`** — for each listener, for each `{event, method}` pair in `listener.handles`, append `{listener: listener.fqcn, method}` to the matching event's `handled_by` array. Sorted by `listener` ascending then `method` ascending. Orphan registrations (events the listener handles but EventScanner didn't find) are silently skipped.

2. **Disambiguate `kind: ambiguous`** — DispatchScanner emits `X::dispatch(...)` Dispatchable-form sites with `kind: ambiguous` because the class could be either an event or a job. The disambiguator finalizes each: `kind = event` if `target` is in `events[]`, otherwise `kind = job`. EventScanner's dispatch-site seeding ensures most event classes are already in `events[]`; classes that aren't fall through to `job`.

3. **`listeners[*].dispatches`** — for each dispatch site whose enclosing class FQCN matches a listener and whose enclosing method is in that listener's set of `handles[*].method` values, append a `$defs/dispatch` entry to that listener's `dispatches` array. Dispatches emitted from a custom handler method (`handleOrderPlaced`, `handleOrderRefunded`, …) are attributed to the listener, not dropped.

4. **`observers[*].dispatches` and `jobs[*].dispatches`** — same as #3 but matching observer FQCNs and methods drawn from the canonical Eloquent hook enum (`creating`, `created`, `updating`, …), or matching job FQCNs whose enclosing method is `handle`. Sites in non-hook methods on an observer, or in helper methods called from a job's `handle()`, don't appear here.

5. **`events[*].dispatched_from` and `jobs[*].dispatched_from`** — for each site with finalized `kind === 'event'` and `target` matching an event entry, append a `$defs/dispatchSite` entry (`{file, line, method: "Class::method"}`) to that event's `dispatched_from` array. The mirror join runs for `kind === 'job'` against the jobs index.

The cross-link pass deliberately does NOT join `closure_listeners[]` into `events[*].handled_by`. That field's entry shape is `{listener: string, method: string}`; closures have neither. Adding closures would require a schema change (a new entry variant) and is reserved for a future design pass — don't add it without going through `schema-guardian`. Similarly, `closure_listeners[*].dispatches` stays empty for now; populating it requires line-span attribution rather than the class+method join used for `listeners[*].dispatches`.

After cross-link: arrays are sorted (by string content or by `(file, line)` as appropriate) for determinism, `_dispatch_sites` is removed from the section map, and validation runs.

The pass lives in `IndexBuilder::crossLink()`. It's deliberately self-contained — every other piece of cross-scanner logic should be refactored into it rather than scattered across scanners.

## Unresolved dispatches

Static analysis cannot resolve some dispatch sites:

- `event($variable)` — variable holding the class
- `event($container->make('SomeKey'))` — container indirection
- `event("App\\Events\\{$name}")` — string interpolation
- `event($flag ? $a : $b)` — non-resolvable ternary (both branches must be variables/expressions, not concrete `new X()` calls)

These don't disappear. `DispatchScanner` emits each as an entry in `unresolved_dispatches[]` with one of four `reason` codes:

- `dynamic_class_name`
- `container_resolution`
- `string_concatenation`
- `conditional_dispatch`

The intent: surface gaps in the index rather than silently dropping data. A consumer can read `unresolved_dispatches` and decide how to treat it.

## Error handling

- **File-level parse errors** — `AstWalker::walk()` swallows them and returns `null`. The scanner sees no visitor hits for that file.
- **Schema validation errors** — fatal. `IndexBuilder` throws a `RuntimeException` with the violating section path.
- **Missing app root** — `loom:scan` resolves the app root from Laravel itself (`$this->laravel->basePath()`), so this can only happen if Loom is invoked outside a Laravel application.
- **Empty results** — valid. An app with no events, listeners, or observers produces a well-formed index with empty arrays and zero stats.

## Performance

No caching in the current implementation. Acceptable scan times:

- Fresh `laravel new` app: < 1s
- Small real app (~50 files in scope): < 5s
- Medium real app (~500 files in scope): < 30s

If you exceed these, the first move is sharing parsed ASTs across scanners (currently each scanner re-parses every file it walks). Caching to disk adds invalidation complexity and is a deliberate non-goal at this point.

## Extension points

Adding a new scanner is one file plus one registration line in `ScanCommand`. The `Scanner` contract has been designed so a new scanner can contribute new sections without touching existing scanners — see [contributing.md](contributing.md) for the workflow.

Runtime data merging (e.g. promoting `confidence` from `high` to verified after a trace) would attach to existing dispatch entries via a separate overlay, not by mutating scanner output. Loom stays static; overlays would be a layer above.
