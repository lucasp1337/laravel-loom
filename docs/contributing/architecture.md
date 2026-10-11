# Architecture

How Loom is wired. What each scanner matches is documented on the scanner and visitor classes; the tests under `tests/Unit` and `tests/Feature` pin it.

Two halves: the build side turns source files into an `Index`, and the read side consumes a written `index.json`. They meet at the schema, `schema/loom-index.schema.json`.

## Build side

### Pipeline

```
per scanner:    scan scope -> discovery -> AST parse -> emit
IndexBuilder:   merge -> cross-link -> strip _* sections -> Index
loom:scan:      validate against the schema -> write index.json
```

`IndexBuilder::build()` runs the scanners that `DefaultScanners` registers, merges their sections (a scanner that returns a section the schema does not know makes `build()` throw), runs the cross-link pass, drops `_*` sections and wraps the result in an `Index`. `loom:scan` resolves the scan scope, calls `build()`, validates the payload with `IndexBuilder::validate()` and writes the file. A schema violation fails the command and nothing invalid is written.

`DefaultScanners` hands one `ScanScope` (scan paths plus `scan.exclude`) and one shared `AstWalker` to every scanner. The walker swallows parse errors and counts them, which is where `loom:scan` gets its skipped-file count. See [Scan configuration](../reference/scan-config.md).

### Scanner contract

```php
interface Scanner
{
    /** @return array<string, array<int, mixed>> entries keyed by schema section */
    public function scan(string $appRoot): array;
}
```

A scanner is stateless: it takes the app root and returns entries keyed by schema section. A section it does not contribute is omitted, and sections from several scanners are concatenated. `DispatchScanner` also returns the underscore-prefixed `_dispatch_sites`, which feeds the cross-link pass and is stripped before validation.

Keep three concerns apart inside a scanner:

- **Discovery**: which files and classes to read. Strategies are a filesystem walk (a convention directory, or all of `app/`), provider parsing (reading `$listen` arrays and `Event::listen()` calls from provider classes) and an attribute scan (`#[ObservedBy]`). Hybrids are common: events come from `app/Events/` and from dispatch-site seeding.
- **Parsing**: visitors over the AST, via `AstWalker`.
- **Emission**: schema-shaped, sorted arrays; no JSON encoding. Entries are sorted by FQCN (or the natural key) and scalar arrays inside an entry are sorted ascending, so the output is stable across runs and machines.

#### Class-based primitives

Events, jobs, mailables and notifications share one skeleton: walk a convention directory, seed more classes from dispatch sites, locate them through PSR-4 and merge by FQCN. `Scanners\Discovery\ClassPrimitiveDiscovery` owns the flow and each primitive supplies a `ClassSpec`; its scanner is only construction plus `discover()`. A spec declares:

- the convention directory and the class visitor (a `ClassRecordVisitor`);
- how a class record becomes a location and an entry (queued state, queue config, channels);
- which visitors read dispatch sites and which targets they seed, each flagged ambiguous or not;
- `admitsAmbiguous()`, the guard for targets reached only through the Dispatchable form (`X::dispatch()` is an event or a job).

Seeded targets already found by the directory walk are skipped; the rest must resolve through PSR-4 to an admitted file declaring that FQCN. A target stays ambiguous only while every site that reached it was. A new class-based primitive is a new spec plus a thin scanner; primitives with other discovery (listeners, observers, routes, schedule) keep their own scanners.

### Building blocks

| Piece | Role |
| --- | --- |
| `Support\AstWalker` | Parses a file and attaches `NameResolver` before any visitor, so names are fully qualified. |
| `Support\Ast\*` | The only code that reads php-parser call nodes: `Args` (positional and named arguments), `CallSite`, `CallChain`, `Literal`, `ClassRef`, `Callables`, `ValueLists`, `EventsDispatcher`. |
| `Scanners\Visitors\CollectingVisitor` | Base for visitors reused across files; its final `beforeTraverse()` calls `reset()`. `TracksClassScope` adds the enclosing class and method. |
| `Support\Fqcn` | The one home for class-name strings: normalise, short, namespace, compare, `Class@method` splitting. |
| `Scanners\Discovery\ClassPrimitiveDiscovery` | The shared skeleton for the class-based primitives, described above. |
| `Scanners\Dispatch\DispatchRules` | One row per recognised dispatch call shape. `DispatchRuleMatcher` evaluates the table for `DispatchSiteVisitor`, and `eventDiscovery()` is the subset event discovery reads. |
| `Support\ClassHierarchyResolver` | Lazy `extends` / `implements` / `use` resolution across files under the scan paths. Vendor classes are opaque leaves. Each consumer builds its own. See [class hierarchy](class-hierarchy.md). |
| `Support\ChainModifierExtractor` | Maps a dispatch chain's `->onQueue()`, `->delay()` and similar links to `overrides`. |

Architecture tests enforce the boundaries between them:

- `AstConfinementTest`: visitors never name `PhpParser\Node\Arg`.
- `FqcnConfinementTest`: class-name string handling lives in `Support\Fqcn`.
- `FieldSchemaParityTest`: the `Field` and `Sections` enums cover the schema (see [Field names](#field-names)).

### Visitor conventions

- **Read on `leaveNode`, not `enterNode`.** NameResolver rewrites child names while it descends, so an outer node's inner `new X` or `X::class` is only resolved on leave. The one exception is `namespacedName` on a class itself, which is set on enter.
- **Extend `CollectingVisitor` and implement `reset()`.** Scanners reuse one visitor instance across all files in the discovery loop, and the base class calls `reset()` from a final `beforeTraverse()`.
- **Expose state through a getter**, not by mutating an external array. The scanner reads the collected records after each `walk()`.
- **Read call arguments through `Support\Ast`.** Never name `PhpParser\Node\Arg` in a visitor or scanner.
- **A new dispatch form is a `DispatchRules` row**, plus a case in `tests/Unit/Dispatch/DispatchRuleMatcherTest.php`. Do not add shape checks to a visitor.

### IndexBuilder and the section registry

`Index` holds a section map keyed by section name plus the envelope scalars (`schema_version`, `loom_version`, `scanned_at`, `laravel_version`). `SectionRegistry` is the single ordered source of truth for the sections: it drives the output body order and the `stats` block (one count per section), and flags which sections the UI lists in its sidebar and dashboard (all except `model_events`). `Index::toArray()` rebuilds `stats` and the section bodies by iterating it.

Adding a top-level section means a new `Sections` case plus a `SectionRegistry` entry, plus the schema. `Index` and `IndexBuilder` do not change. Check the places that enumerate sections too: the cross-link phases, `loom:diff` specs, MCP tools and the UI ([ADR 0007](adr/0007-scanners-not-an-extension-point.md)).

### Field names

Field names are not magic strings. Three flat enums own the index's string keys:

- `Sections` for the top-level section names.
- `MetaField` for the envelope scalars and `stats`.
- `Field` for every per-entry property name.

The serializer, the cross-link phases and the `loom:diff` engine key off these enums via `->value`. Only deliberately internal, non-emitted keys (the `_*` sections, transient cross-link tags) stay raw strings. `tests/Unit/Index/FieldSchemaParityTest.php` asserts the enums exactly cover the schema's property surface, so a new field needs a case in the enum and an entry in the schema, or that test fails.

### Cross-link pass

`CrossLinker` builds FQCN lookups once, then runs ordered `CrossLinkPhase` classes over a shared `CrossLinkContext`. It is the only place that reads data across scanners. Phase order matters: disambiguation and closure ownership come before attribution, and sorting is last.

1. `HandledByPhase`: `events[].handled_by` from `listeners[].handles`. Closure listeners are not joined, as the entry shape needs a class name.
2. `AmbiguousDisambiguationPhase`: an `X::dispatch()` site becomes an event when `X` is in `events[]`, else a job. Attribution and `dispatched_from` read the finalized kind, so this runs before them.
3. `ClosureOwnershipPhase`: a site inside a registration closure (closure listener or route closure) keeps its closure tag; a site in any other closure (`DB::transaction(fn () => ...)`, `each`, `tap`) is handed to the enclosing class method. Attribution reads the tag, so this runs before it.
4. `DispatchAttributionPhase`: `dispatches` on listeners (the methods in `handles`), jobs (`handle`) and observers (Eloquent hook methods). A dispatch in a helper method called from a handler is not attributed to the handler.
5. `ClosureDispatchAttributionPhase`: `closure_listeners[].dispatches` by source span `[line, end_line]` in the same file.
6. `DispatchedFromPhase`: `dispatched_from`, `sent_from` and `notified_from` on events, jobs, mailables and notifications.
7. `RouteDispatchAttributionPhase`: `routes[].dispatches` by controller class and method, or by span for a closure route.
8. `SortPhase`: deterministic order.

Add a relation by adding a phase; the orchestrator does not change. Two scanners never write the same field. The order lives in `CrossLinker`.

### Unresolved dispatches

A dispatch target Loom cannot resolve is never dropped. `DispatchScanner` emits it to `unresolved_dispatches[]` with one of four reasons: `dynamic_class_name`, `container_resolution`, `string_concatenation`, `conditional_dispatch`. A consumer reads that list to see the gaps in the index.

### Errors and performance

- A file that fails to parse is skipped and counted.
- A schema violation is fatal for `loom:scan`: it prints the errors and writes nothing.
- An app with nothing to find yields a valid index of empty arrays.
- Nothing is cached. Scanners each parse the files they walk, so sharing parsed ASTs across scanners is the first thing to try if scan time becomes a problem. The [benchmark suite](adr/0006-benchmark-suite.md) gates on counts, not wall time.

## Read side

Everything that consumes a written index reads the file and does not run scanners, with one exception noted below.

- `IndexLoader` and the typed getters on `Index` are the public read model ([PHP API](../reference/php-api.md)).
- `src/Check/` (`loom:check`) and `src/Diff/` (`loom:diff`) run rules and specs over a loaded `Index`. `CheckRuleRegistry` supplies ordered rules keyed by `RuleKey`, `CheckRunner` collects violations and a `CheckFormatter` layer renders text, JSON or markdown; the diff layer mirrors that with a spec registry. See [Check rules and formats](../reference/check-rules-and-formats.md).
- `src/Query/` answers transport-agnostic questions (`IndexQuery`) over a loaded `Index` and returns DTOs and enums. `src/Mcp/` and `src/Ui/` are adapters on top of it and do not depend on each other; each supplies its own `IndexSource`.
- The MCP `IndexRepository` runs `loom:scan` once through Artisan when no snapshot exists and auto-scan is on. The UI only reads the file.

See [Ask an agent](../guides/ask-an-agent.md), [MCP tools](../reference/mcp-tools.md), [Browse the UI](../guides/browse-the-ui.md) and [UI config](../reference/ui-config.md) for the consumer view.
