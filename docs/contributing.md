# Contributing

How to develop on Loom locally.

## Prerequisites

- PHP **8.3+** with `ext-mbstring`, `ext-xml`, `ext-dom`, `ext-xmlwriter`
- Composer 2
- Docker (optional but recommended — see below)

The required PHP extensions are common but not universal. If your host PHP lacks them, use the Docker image bundled with the repo.

## Local toolchain

Install dependencies:

```bash
composer install
```

Run the toolchain:

```bash
vendor/bin/phpstan analyse        # level 8, must be zero errors
vendor/bin/pint --test            # dry-run; vendor/bin/pint to apply
vendor/bin/pest                   # full test suite
```

All three must pass before any commit.

## Docker toolchain via `just`

If your host PHP lacks the required extensions (common on minimal Linux installs or PHP 8.5 builds), the repo ships a `Justfile` that wraps every common task in a Docker container. Install [just](https://github.com/casey/just), then:

```bash
just build           # build the Docker dev image (once)
just install         # composer install inside the container
just check           # full toolchain: PHPStan + Pint --test + Pest
just test            # just the Pest suite
just phpstan         # just PHPStan
just pint            # apply Pint formatting
just shell           # interactive shell inside the container
```

Run `just` with no arguments to see every recipe.

The Dockerfile installs `mbstring`, `xml`, `dom`, `xmlwriter`, and `zip` on top of `php:8.3-cli-alpine`. Composer is included.

If you'd rather skip `just`, the raw equivalents are:

```bash
docker build -t laravel-loom-dev:latest .
docker run --rm -v "$(pwd):/app" laravel-loom-dev:latest vendor/bin/pest
```

## Documentation site

This `docs/` tree is published as a [MkDocs Material](https://squidfunk.github.io/mkdocs-material/) site. It builds straight from these Markdown files — editing docs is just editing Markdown, no extra step.

```bash
just docs-serve      # live-reload preview at http://localhost:8000
just docs-build      # strict build into ./site (fails on broken links)
just docs-deploy     # build + publish to the gh-pages branch
```

Publishing is **local and deliberate** — no GitHub Actions run on push. `just docs-deploy` builds the site in Docker, stages the `gh-pages` branch, and pushes it with your own git credentials; GitHub Pages then serves that branch. Run it whenever you want the live site refreshed (it does not happen automatically). `nav` and theme live in `mkdocs.yml` at the repo root.

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
gates on entry counts (not wall time). The `composer` scripts run on the host;
`just bench [...]` runs them in Docker:

```bash
composer bench                            # run all sizes, print the table
php benchmarks/bench.php --size=medium    # one size
php benchmarks/bench.php --json           # machine-readable
composer bench:baseline                   # (re)write benchmarks/baseline.json
composer bench:assert                     # fail on count drift vs baseline
```

Regenerate and review the baseline whenever a change legitimately moves the
counts. Full details in [benchmarks/README.md](../benchmarks/README.md); rationale
in [ADR 0006](adr/0006-benchmark-suite.md).

## Repository structure

```
src/                        # production code
  LoomServiceProvider.php  # registers loom:scan and loom:show
  Console/                  # the two artisan commands
  Contracts/Scanner.php     # the one-method scanner interface
  Index/                    # IndexBuilder + Index value object
  Scanners/                 # one file per primitive + Visitors/ subdir
  Support/AstWalker.php     # parser + NameResolver wrapper

schema/loom-index.schema.json   # the contract for every emitted index

tests/
  Unit/                     # visitor tests, heredoc snippets
  Feature/                  # scanner + IndexBuilder tests, fixture-driven
  Fixtures/                 # minimal app trees per scenario

docs/                       # contributor docs (you are here)
```

## Adding a scanner

The workflow is automated via a chain of specialized agents (see `AGENTS.md`). The high-level steps:

1. **Design.** Write `docs/scanners/{name}.md` covering what it detects, what it emits to the schema, edge cases, and known limitations. Mirror the structure of the existing scanner docs.

2. **Implement.** Create `src/Scanners/{Name}Scanner.php` implementing `Lucasp\Loom\Contracts\Scanner`. Three concerns must live in separate methods:
   - Discovery (filesystem walk / provider reflection / attribute scan)
   - Parsing (delegated to one or more `NodeVisitor` classes in `src/Scanners/Visitors/`)
   - Emission (build schema-shaped arrays, sort deterministically)

3. **Visitors.** Subclass `PhpParser\NodeVisitorAbstract`. Read on `leaveNode` (NameResolver child-first ordering — see [architecture.md](architecture.md)). Reset state in `beforeTraverse()`. Expose collected data via a getter.

4. **Register.** Add `$builder->register(new {Name}Scanner);` to `src/Console/ScanCommand.php`.

5. **Test.** Three layers:
   - Unit tests in `tests/Unit/` that feed heredoc snippets to each visitor and assert collected output
   - Integration test in `tests/Feature/` that points the scanner at a fixture in `tests/Fixtures/{name}-fixture-app/`
   - End-to-end test that registers the scanner against `IndexBuilder` and asserts the resulting index validates against the schema

6. **Toolchain.** PHPStan level 8, Pint clean, Pest green.

## Adding a fixture

Fixtures live at `tests/Fixtures/{scenario}-fixture-app/`. Each fixture is a minimal directory tree mimicking a Laravel app:

- No `vendor/`, no autoloader, no bootstrap
- Just the PHP files the scanners need to parse
- Use `namespace App\…;` declarations matching PSR-4 paths
- Classes don't need to extend the real Laravel base classes — visitors operate at the AST level and recognize names without loading them

Pint skips `tests/Fixtures/` (configured via `pint.json`) because fixture files often need non-standard formatting to exercise edge cases.

## Working on the schema

`schema/loom-index.schema.json` is the contract for every emitted index. Changes go through the `schema-guardian` agent for review and require:

1. A version-bump assessment (patch / minor / major per the rules in [schema.md](schema.md))
2. Updated sample outputs in `README.md` and any affected tests
3. A `CHANGELOG.md` entry calling out the version implication

The `IndexBuilder` validates every built index against the schema before writing. If you change scanner output, expect the validation to catch mismatches early.

## Working on the agents

`.claude/agents/` and `.claude/commands/` codify the development workflow. Edits there should be deliberate:

- Agent prompts should give *insight*, not duplicate `README.md`
- New slash commands should chain existing agents rather than introduce parallel workflows
- `audit-spec`-style guardrails should reference observable behavior (tests, code), not frozen spec documents

If you fork the repo to build a different Laravel introspection tool, the agents may give you a useful starting point — they're intentionally generic about Laravel and specific about Loom.

## Style

- `declare(strict_types=1);` at the top of every PHP file
- PHPDoc on every public method, especially structured return types
- Comments only when the WHY isn't obvious from the code — no narration of the WHAT
- One scanner per Laravel primitive; resist merging scanners even when they share visitors
- Cite the relevant schema section in commit messages when changing scanner output: `feat(observers): emit hooks alphabetically (cites $defs/observer)`

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
