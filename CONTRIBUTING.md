# Contributing

How to develop on Loom locally.

## Where docs live

- `docs/` is **consumer-facing** and is the published docs site. Write for someone using Loom in their app: no `src/` paths, no ADR or issue references.
- `docs/contributing/` is **contributor-only**: `architecture.md`, `class-hierarchy.md`, `add-a-scanner.md` and `adr/`. It is published under Contributing in the site nav, but it is written for contributors: it may cite source paths and ADRs, and it must match the code.
- `CHANGELOG.md` entries are one short line each, linking the PR.

Rules for consumer docs:

1. One fact, one page. Link instead of restating.
2. Never hand-write command output or JSON samples: add an `OutputSample` case (`tools/Reference/`) and place its `output:KEY` region; it runs Loom on `tests/Fixtures/docs-order-app` (plus `docs-order-branch` for check and diff).
3. No issue, ADR or `src/` references.
4. Say what Loom records and misses, not how Laravel works. Link the Laravel docs when a reader needs the framework behaviour.
5. Make no version-specific framework claims.
6. Leave out what does nothing for the reader: provenance and history.
7. A `php` fence comes from `docs/examples/` or `docs/snippets/` through a generated region; code no test runs gets `<!-- illustrative -->` above its fence.

The drift-guard test `tests/Docs/DocsInSyncTest.php` (run by `just test`) fails when documented commands, config keys, rules or samples no longer match the code, and when consumer docs contain forbidden references. `tests/Docs/GuardsTest.php` checks the hand-written layer: names of commands, options, config keys, reasons, MCP tools and classes must exist in the code; `php` fences follow rule 7; each page stays within its word budget in `tools/Guards/WordBudget.php` (lower the budget when you trim); and each page follows the heading outline of its kind (`tools/Guards/PageKind.php`). Fix the docs, not the test.

Reference pages carry generated regions: a block between `<!-- generated:NAME -->` and `<!-- /generated:NAME -->` markers, built from `schema/loom-index.schema.json`, the MCP tool classes, `config/loom.php` and the Artisan command signatures. After changing any of those, run `just docs-generate`; `just docs-check` (and `tests/Docs/ReferenceDriftTest.php`) fails when a committed page is stale. The full steps and how to add a region are under [Documentation site](#documentation-site).

## Prerequisites

- Docker
- [just](https://github.com/casey/just)

Nothing else is needed on the host; PHP and Composer run inside the image.

## Toolchain

Just and Docker are the only supported toolchain: no local PHP, no composer scripts. Install [Docker](https://docs.docker.com/get-docker/) and [just](https://github.com/casey/just), then:

```bash
just build           # build the Docker dev image (once)
just install         # composer install inside the container
just verify          # the full gate, fail-fast; must pass before any commit
just fix             # apply rector, pint and regenerate docs
just test            # just the Pest suite
just test-filter X   # a focused Pest run
```

Run `just` with no arguments to see every recipe. `just verify` runs Pint, PHPStan (level 8), Rector (dry run), Pest, the benchmark count gate, the generated-docs check and the strict docs build, and stops at the first failure.

The Dockerfile installs `mbstring`, `xml`, `dom`, `xmlwriter`, and `zip` on top of `php:8.3-cli-alpine`, plus Composer.

## Documentation site

The `docs/` tree is published as a [Zensical](https://zensical.org/) site. It builds straight from these Markdown files — editing docs is just editing Markdown, no extra step.

```bash
just docs-generate   # refresh the generated regions
just docs-check      # fail if a generated region drifted from its source; writes nothing
just docs-serve      # regenerate, then live-reload preview at http://localhost:8000
just docs-build      # check, then strict build from a scratch copy (fails on broken links)
```

### Generated regions

Sources in `tools/Pages/` implement `RegionSource` and return page-independent regions (`name => markdown`); they never name a page. A page shows a region wherever it carries the marker pair, and one region may appear on several pages:

```markdown
<!-- generated:command:loom:check -->
<!-- /generated:command:loom:check -->
```

- To show a region on another page, add the marker pair there and run `just docs-generate`.
- To add a region, return a `GeneratedRegion` from a source. Fixed names are cases of `Regions\Region`; families use `Regions\RegionPrefix` plus a key. Marker syntax lives only in `Regions\Markers`.
- To add a source, add a class in `tools/Pages/` implementing `RegionSource` with a `SourceContext` constructor; it is discovered automatically. Hand-written prose for a source lives in typed classes under `tools/Reference/`.
- The generator fails on a marker no source produces, a region placed on no page, and unbalanced, nested, duplicate or malformed markers.

The site is published by the `Docs` GitHub Pages workflow only, which runs on `workflow_dispatch` or a published release; nothing publishes from a local machine. The look is `docs/stylesheets/loom.css` plus the home-page template in `overrides/`. `nav` and theme options live in `mkdocs.yml` at the repo root, which Zensical reads directly.

## Scanning an external Laravel app

The `Justfile` exposes a quick way to point Loom at any Laravel app on disk:

```bash
just scan /path/to/your/laravel/app                # prints stats
just scan-json /path/to/your/laravel/app > index.json   # writes the full index
```

Useful for verifying behavior on real codebases beyond `tests/Fixtures/`.

The `scan` / `scan-json` recipes and `loom:scan` itself register the same scanner
set from `Lucasp\Loom\Scanners\DefaultScanners` — the single source of truth for
which scanners run. Add or remove scanners there, not in each consumer.

## Benchmarking

The benchmark suite measures scan cost across deterministic generated apps and
gates on entry counts (not wall time):

```bash
just bench                  # run all sizes, print the table
just bench --size=medium    # one size
just bench --json           # machine-readable
just bench-baseline         # (re)write benchmarks/baseline.json
just bench --assert         # fail on count drift vs baseline
```

Regenerate and review the baseline whenever a change legitimately moves the
counts. Full details in [benchmarks/README.md](benchmarks/README.md); rationale
in [ADR 0006](docs/contributing/adr/0006-benchmark-suite.md).

## Repository structure

```
src/                        # production code
  LoomServiceProvider.php  # registers the artisan commands, MCP server and UI
  Console/                  # loom:scan, show, diff, check, mcp
  Contracts/Scanner.php     # the one-method scanner interface
  Index/                    # IndexBuilder, Index, IndexLoader, Model/
  Check/  Diff/  Query/  Mcp/  Ui/
  Scanners/                 # one file per primitive + Visitors/ subdir
  Support/Ast/              # the only non-visitor home of php-parser: AstWalker, argument, call-chain and literal readers

schema/loom-index.schema.json   # the contract for every emitted index

tests/
  Unit/                     # visitor tests, heredoc snippets
  Feature/                  # scanner + IndexBuilder tests, fixture-driven
  Fixtures/                 # minimal app trees per scenario

docs/                       # consumer-facing docs (published site)
  contributing/             # contributor-only docs
```

## Where does X go?

| Change | Goes in | Test to touch |
| --- | --- | --- |
| New dispatch form (`X::foo($job)`, a helper) | a row in `Scanners/Dispatch/DispatchRules.php` | `tests/Unit/Dispatch/DispatchRuleMatcherTest.php` |
| New schedule chain method or frequency | `Scanners/Schedule/` (`ScheduleModifier`, `ScheduleConstraint`, `CronHelper`) | `tests/Unit/Scanners/CronHelperTest.php`, `tests/Feature/ScheduleScannerTest.php` |
| New class-based primitive | a `ClassSpec` in `Scanners/Discovery/`, a thin scanner, `DefaultScanners` | `tests/Unit/Scanners/ClassSpecsTest.php`, an end-to-end test; see [Add a scanner](docs/contributing/add-a-scanner.md) |
| New scanner or top-level section | `Contracts\Scanner`, `Sections`, `SectionRegistry`, schema, `DefaultScanners` | scanner and `IndexBuilder` tests, `FieldSchemaParityTest`; see [Add a scanner](docs/contributing/add-a-scanner.md) |
| New field on an existing entry | `Index/Field.php`, `schema/loom-index.schema.json`, the scanner DTO in `Dto/`, `Index/Model/` and `Hydrate` | `tests/Unit/Index/FieldSchemaParityTest.php`, `tests/Unit/Index/IndexApiTest.php` |
| New way of reading a php-parser node | `Support/Ast/` (php-parser is imported only there and in `Scanners/Visitors/`) | `tests/Unit/Support/Ast/`, `PhpParserConfinementTest` |
| New cross-link relation | a `CrossLinkPhase` in `Index/CrossLink/`, appended to `CrossLinker`'s list | `tests/Unit/Index/CrossLink/`; see [Cross-link pass](docs/contributing/architecture.md#cross-link-pass) |
| New `loom:check` rule | `Check/Rules/`, registered in `CheckRuleRegistry` | `tests/Unit/Check/RulesTest.php` |
| New MCP tool | `Mcp/Tools/`, listed in `LoomMcpServer::$tools`, a `ToolDoc` in `tools/Reference/McpToolDocs.php` | `tests/Feature/Mcp/`, `tests/Unit/McpSurfaceTest.php` |
| New UI page | a Livewire component in `Ui/Livewire/`, a view in `resources/views/livewire/`, registered in `LoomUiServiceProvider` | `tests/Feature/Ui/PagesTest.php` |
| New UI section column | `Ui/SectionPresentation.php` | `tests/Unit/Ui/SectionPresentationTest.php` |
| New config key | `config/loom.php` (its comment becomes the docs row), plus `Support/ScanConfigKey.php` for `scan.*` or `Ui/LoomConfig.php` for `ui.*` | `tests/Docs/ReferenceDriftTest.php` |
| New command output or index sample in the docs | an `OutputSample` case, a marker pair on the page | `tests/Docs/ReferenceDriftTest.php`; run `just docs-generate` |
| New generated docs region | a `Region` case or `RegionPrefix`, a `RegionSource` in `tools/Pages/`, a marker pair on the page | `tests/Docs/ReferenceDriftTest.php`; run `just docs-generate` |

## Adding a scanner

See [Add a scanner](docs/contributing/add-a-scanner.md) for the steps and a checklist. The scanner and visitor docblocks are the behaviour spec. With an agent, the `/add-scanner` command runs the same steps as a chain.

## Adding a fixture

Fixtures live at `tests/Fixtures/{scenario}-fixture-app/`. Each fixture is a minimal directory tree mimicking a Laravel app:

- No `vendor/`, no autoloader, no bootstrap
- Just the PHP files the scanners need to parse
- Use `namespace App\…;` declarations matching PSR-4 paths
- Classes don't need to extend the real Laravel base classes — visitors operate at the AST level and recognize names without loading them

Pint skips `tests/Fixtures/` (configured via `pint.json`) because fixture files often need non-standard formatting to exercise edge cases.

## Working on the schema

`schema/loom-index.schema.json` is the contract for every emitted index. Changes go through the `schema-guardian` agent for review and require:

1. A version-bump assessment (patch / minor / major per the rules in [schema.md](docs/reference/schema.md))
2. Updated sample outputs in `README.md` and any affected tests
3. A `CHANGELOG.md` entry calling out the version implication

`loom:scan` validates every built index against the schema and writes nothing when it does not conform. If you change scanner output, expect the validation to catch mismatches early. Field names are enums (`Sections`, `MetaField`, `Field`); `FieldSchemaParityTest` fails when they and the schema diverge.

## Working on the agents

`.claude/agents/` and `.claude/commands/` codify the development workflow. Edits there should be deliberate:

- Agent prompts should give *insight*, not duplicate `README.md`
- New slash commands should chain existing agents rather than introduce parallel workflows
- `audit-spec`-style guardrails should reference observable behavior (tests, code), not frozen spec documents

If you fork the repo to build a different Laravel introspection tool, the agents may give you a useful starting point — they're intentionally generic about Laravel and specific about Loom.

## Style

- `declare(strict_types=1);` at the top of every PHP file
- PHPDoc on every public method, especially structured return types
- Comments only when the WHY isn't obvious from the code — no narration of the WHAT. The exception is branching on AST shapes: put a short comment on each non-obvious branch saying what the shape means
- No `elseif` / `else if` chains: dispatch with `match` on a typed enum, or use guard clauses. A test fails on new ones in `src/`
- Prefer `Illuminate\Support` helpers (`Arr`, `Str`, `Collection`) over native array, string and sort functions where behaviour is identical; `tests/Unit/Support/NativeFunctionsTest.php` forbids new native calls unless allowlisted with a reason
- One scanner per Laravel primitive; resist merging scanners even when they share visitors
- Cite the relevant schema section in commit messages when changing scanner output: `feat(observers): emit hooks alphabetically (cites $defs/observer)`

## Rector

`rector.php` applies the mechanical style rules to `src/`:

```bash
just rector       # dry run, exits non-zero on a diff
just fix          # apply rector, then pint to import the new class names, then regenerate docs
```

Project rules live in `tools/rector/` (tests in `tests/Rector/`):

- `IfElseifChainToMatchRector`: an `if / elseif / else` chain testing one variable with `===` against literals or class constants becomes a `match`, when every branch returns or assigns the same variable. Loose `==`, missing `else`, side-effecting subjects and multi-statement branches are skipped.
- `NativeFunctionToLaravelHelperRector`: native string, array and sort calls become `Str`, `Arr` or `collect()` forms, only where the result is exactly equivalent (argument types come from PHPStan, so untyped arguments are left alone). The class docblock lists each mapping and the shapes it skips.

The native-function rule skips the files `NativeFunctionsTest` allowlists. Rector is not part of CI; run it locally before sending a change that touches branching or array code.

## Data transfer: DTOs, not arrays

Structured data passed between components (visitor → scanner, scanner → cross-link) **must** be a typed DTO, never an associative array. Arrays have no contract: a field rename is silent, a typo crashes at the consumer instead of the producer, and PHPStan can only verify shapes through fragile `array{}` annotations.

Rules:

- Every multi-field record gets a class in `src/Dto/`. Construct with constructor-promoted `public readonly` properties (PHP 8.1+).
- Visitors expose collected records as `list<SomeDto>`. Never `array<int, array{...}>`.
- Scanners consume DTOs from visitors and build the schema-shaped output array **only at the emit boundary** (the last step before returning from `scan()`).
- IndexBuilder's cross-link pass operates on the schema-shaped arrays — that's the public JSON contract and must stay an array. Anything *before* that boundary stays typed.
- Don't add `toArray()` for ergonomics. The conversion is a deliberate boundary, not a frequent operation. Build the schema-shaped row inline at the emit step where the schema field names are visible.
- Single-value tuples (one string, one int) don't need a DTO. Two or more fields do.

When in doubt, ask: "could a typo in a key silently produce wrong output here?" If yes, it's a DTO.

## Releasing

The release workflow is the `/prep-release` slash command:

1. Bumps `IndexBuilder::LOOM_VERSION` (and `composer.json` if a version field is present)
2. Drafts a CHANGELOG entry from the Unreleased section
3. Verifies the toolchain is green
4. Suggests a git tag command

Tag, push, and create the GitHub release manually after reviewing the draft notes.
