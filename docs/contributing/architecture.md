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

`IndexBuilder::build()` does not validate. `loom:scan` calls `IndexBuilder::validate()` on the result, and a schema violation fails the command before anything is written. A scanner that returns a section the schema does not know makes `build()` throw. `DefaultScanners` is the one list of scanners; the CLI and the benchmark suite both use it. See [Scan configuration](../reference/scan-config.md).

### Scanner contract

A scanner is stateless: `scan(string $appRoot)` returns entries keyed by schema section, and sections from several scanners are concatenated. Underscore-prefixed sections (`_dispatch_sites`) are internal: they feed the cross-link pass and are stripped before validation.

Inside a scanner, keep three concerns apart so each can change without touching the others:

- **Discovery** decides which files and classes to read.
- **Parsing** is visitors over the AST, through `AstWalker`.
- **Emission** builds schema-shaped, sorted arrays and does no JSON encoding or formatting.

Events, jobs, mailables and notifications share one discovery skeleton, `ClassPrimitiveDiscovery`, parameterised by a `ClassSpec` per primitive. Other primitives (listeners, observers, routes, schedule) keep their own scanners. See [Add a scanner](add-a-scanner.md) for the steps.

### Visitor conventions

- **Read on `leaveNode`, not `enterNode`.** The exception is `namespacedName` on a class itself.
- **Extend `CollectingVisitor` and implement `reset()`.** One visitor instance is reused across all files in a discovery loop.
- **Expose state through a getter**, not by mutating an external array.
- **Read call arguments through `Support\Ast`**, never `PhpParser\Node\Arg`. `AstConfinementTest` enforces it.
- **Class-name strings go through `Support\Fqcn`.** `FqcnConfinementTest` enforces it.
- **A new dispatch form is a `DispatchRules` row**, not a shape check in a visitor.

Transitive `extends` / `implements` / `use` questions go to `ClassHierarchyResolver`; see [class hierarchy](class-hierarchy.md).

### Sections and fields

Section names, envelope keys and per-entry property names are enums (`Sections`, `MetaField`, `Field`); only internal, non-emitted keys stay raw strings. `FieldSchemaParityTest` fails when the enums and the schema drift apart.

Adding a top-level section is a `Sections` case, a `SectionRegistry` entry and the schema. `Index` and `IndexBuilder` do not change, but check the places that enumerate sections: the cross-link phases, `loom:diff` specs, MCP tools and the UI ([ADR 0007](adr/0007-scanners-not-an-extension-point.md)).

### Cross-link pass

`CrossLinker` is the only place that reads across scanners, and two scanners never write the same relation field: scanners emit their own sections, and the phases join them afterwards. Each `CrossLinkPhase` states what it reads and writes in its docblock.

Order matters because phases consume what earlier ones settle: a dispatch site's kind and closure ownership are final before any attribution reads them, and sorting runs last. A new relation is a new phase appended to `CrossLinker`'s list; the orchestrator and the existing phases do not change.

### Errors and performance

- A file that fails to parse is skipped and counted, and `loom:scan` reports the count.
- A schema violation is fatal for `loom:scan`: it prints the errors and writes nothing.
- An app with nothing to find yields a valid index of empty arrays.
- `AstWalker` parses and name-resolves each file once and keeps the tree for the next scanner (keyed by path and content hash). It admits files until a source-size budget, derived from the memory limit, is spent and then parses per walk, because every scanner sweeps the same files in order and evicting would never hit. Visitors must not mutate the tree. The [benchmark suite](adr/0006-benchmark-suite.md) gates on counts, not wall time.

## Read side

Everything that consumes a written index reads the file and does not run scanners, with one exception noted below.

- `IndexLoader` and the typed getters on `Index` are the public read model ([PHP API](../reference/php-api.md)).
- `src/Check/` (`loom:check`) and `src/Diff/` (`loom:diff`) run rules and specs over a loaded `Index`. See [Check rules and formats](../reference/check-rules-and-formats.md).
- `src/Query/` answers transport-agnostic questions (`IndexQuery`) and returns DTOs and enums. `src/Mcp/` and `src/Ui/` are adapters on top of it and do not depend on each other; each supplies its own `IndexSource`.
- The MCP `IndexRepository` runs `loom:scan` once through Artisan when no snapshot exists and auto-scan is on. The UI only reads the file.

See [Ask an agent](../guides/ask-an-agent.md), [MCP tools](../reference/mcp-tools.md), [Browse the UI](../guides/browse-the-ui.md) and [UI config](../reference/ui-config.md) for the consumer view.
