# Architecture

How Loom is wired. What each scanner matches is documented on the scanner and visitor classes; the tests under `tests/Unit` and `tests/Feature` pin it.

## Pipeline

```
scan scope -> discovery -> AST parse -> emit (per scanner) -> merge -> cross-link -> strip internals -> validate -> Index
```

`IndexBuilder::build()` runs the scanners that `DefaultScanners` registers, merges their sections, runs the cross-link pass, drops `_*` sections, validates against `schema/loom-index.schema.json` (a violation throws; nothing invalid is written) and wraps the result in an `Index`. `loom:scan` is a thin wrapper that writes `index.json`.

Everything else reads a written index and never runs scanners:

- `IndexLoader` and the typed getters on `Index` are the public read model ([PHP API](../reference/php-api.md)).
- `src/Check/` (`loom:check`) and `src/Diff/` (`loom:diff`) run rules and specs over a loaded `Index`.
- `src/Query/` answers transport-agnostic questions (`IndexQuery`) and returns DTOs. `src/Mcp/` and `src/Ui/` are adapters on top of it and do not depend on each other; each supplies its own `IndexSource`.

## Scanner contract

```php
interface Scanner
{
    /** @return array<string, array<int, mixed>> entries keyed by schema section */
    public function scan(string $appRoot): array;
}
```

A scanner is stateless: it takes the app root and returns sections. A section a scanner does not contribute is omitted, and sections from several scanners are concatenated. `DispatchScanner` also returns the underscore-prefixed `_dispatch_sites`, which feeds the cross-link pass and is stripped before validation. Scanners walk files through a `ScanScope` (scan paths plus `scan.exclude`) and a shared `AstWalker`, which swallows parse errors and counts them for `loom:scan`.

Keep three concerns apart inside a scanner: discovery (which files and classes), parsing (visitors) and emission (schema-shaped, sorted arrays; no JSON encoding).

## Building blocks

| Piece | Role |
| --- | --- |
| `Support\AstWalker` | Parses a file and attaches `NameResolver` before any visitor, so names are fully qualified. |
| `Support\Ast\*` | The only code that reads php-parser call nodes: `Args` (positional and named arguments), `CallSite`, `CallChain`, `Literal`, `ClassRef`, `Callables`, `ValueLists`, `EventsDispatcher`. Visitors never name `PhpParser\Node\Arg` (`AstConfinementTest`). |
| `Scanners\Visitors\CollectingVisitor` | Base for visitors reused across files; its final `beforeTraverse()` calls `reset()`. `TracksClassScope` adds the enclosing class and method. |
| `Support\Fqcn` | The one home for class-name strings: normalise, short, namespace, compare, `Class@method` splitting (`FqcnConfinementTest`). |
| `Scanners\Discovery\ClassPrimitiveDiscovery` | The shared skeleton for events, jobs, mailables and notifications: walk the convention directory, seed more classes from dispatch sites, locate them through PSR-4, merge by FQCN. Each primitive is a `ClassSpec`. |
| `Scanners\Dispatch\DispatchRules` | One row per recognised dispatch call shape. `DispatchRuleMatcher` evaluates the table for `DispatchSiteVisitor`, and `eventDiscovery()` is the subset event discovery reads. |
| `Support\ClassHierarchyResolver` | Lazy, per-build `extends` / `implements` / `use` resolution across files under the scan paths. Vendor classes are opaque leaves. See [class hierarchy](class-hierarchy.md). |
| `Support\ChainModifierExtractor` | Maps a dispatch chain's `->onQueue()`, `->delay()` and similar links to `overrides`. |

Visitors read on `leaveNode`, not `enterNode`: NameResolver rewrites child names while it descends, so an outer node's inner `new X` or `X::class` is only resolved on leave. The exception is `namespacedName` on a class itself, which is set on enter.

## Cross-link pass

`CrossLinker` builds FQCN lookups once, then runs ordered `CrossLinkPhase` classes over a shared `CrossLinkContext`. It is the only place that reads data across scanners. Phase order matters:

1. `HandledByPhase`: `events[].handled_by` from `listeners[].handles`. Closure listeners are not joined, as the entry shape needs a class name.
2. `AmbiguousDisambiguationPhase`: an `X::dispatch()` site becomes an event when `X` is in `events[]`, else a job.
3. `ClosureOwnershipPhase`: a site inside a registration closure (closure listener or route closure) keeps its closure tag; a site in any other closure (`DB::transaction(fn () => ...)`, `each`, `tap`) is handed to the enclosing class method.
4. `DispatchAttributionPhase`: `dispatches` on listeners (the methods in `handles`), jobs (`handle`) and observers (Eloquent hook methods).
5. `ClosureDispatchAttributionPhase`: `closure_listeners[].dispatches` by source span `[line, end_line]` in the same file.
6. `DispatchedFromPhase`: `dispatched_from`, `sent_from` and `notified_from` on events, jobs, mailables and notifications.
7. `RouteDispatchAttributionPhase`: `routes[].dispatches` by controller class and method, or by span for a closure route.
8. `SortPhase`: deterministic order.

Add a relation by adding a phase; the orchestrator does not change. Two scanners never write the same field.

## Unresolved dispatches

A dispatch target Loom cannot resolve is never dropped. `DispatchScanner` emits it to `unresolved_dispatches[]` with one of four reasons: `dynamic_class_name`, `container_resolution`, `string_concatenation`, `conditional_dispatch`.

## Errors and performance

A file that fails to parse is skipped and counted; a schema violation is fatal; an app with nothing to find yields a valid index of empty arrays. Nothing is cached, and sharing parsed ASTs across scanners is the first thing to try if scan time becomes a problem.

## Extension points

Scanners are not a public extension point ([ADR 0007](adr/0007-scanners-not-an-extension-point.md)). To add one, see [Add a scanner](add-a-scanner.md).
