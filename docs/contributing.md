# Contributing

How to develop on Atlas locally.

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

## Docker toolchain

If your host PHP lacks the required extensions (common on minimal Linux installs or PHP 8.5 alpha builds):

```bash
docker build -t laravel-atlas-dev:latest .
docker run --rm -v "$(pwd):/app" laravel-atlas-dev:latest vendor/bin/pest
```

The Dockerfile installs `mbstring`, `xml`, `dom`, `xmlwriter`, and `zip` on top of `php:8.3-cli-alpine`. Composer is included.

Substitute `vendor/bin/phpstan` or `vendor/bin/pint` in the same `docker run` invocation. A convenience triple-run:

```bash
docker run --rm -v "$(pwd):/app" laravel-atlas-dev:latest sh -c \
  "vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --test && vendor/bin/pest"
```

## Repository structure

```
src/                        # production code
  AtlasServiceProvider.php  # registers atlas:scan and atlas:show
  Console/                  # the two artisan commands
  Contracts/Scanner.php     # the one-method scanner interface
  Index/                    # IndexBuilder + Index value object
  Scanners/                 # one file per primitive + Visitors/ subdir
  Support/AstWalker.php     # parser + NameResolver wrapper

schema/atlas-index.schema.json   # the contract for every emitted index

tests/
  Unit/                     # visitor tests, heredoc snippets
  Feature/                  # scanner + IndexBuilder tests, fixture-driven
  Fixtures/                 # minimal app trees per scenario

docs/                       # contributor docs (you are here)
```

## Adding a scanner

The workflow is automated via a chain of specialized agents (see `AGENTS.md`). The high-level steps:

1. **Design.** Write `docs/scanners/{name}.md` covering what it detects, what it emits to the schema, edge cases, and known limitations. Mirror the structure of the existing scanner docs.

2. **Implement.** Create `src/Scanners/{Name}Scanner.php` implementing `Lucasp\Atlas\Contracts\Scanner`. Three concerns must live in separate methods:
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

`schema/atlas-index.schema.json` is the contract for every emitted index. Changes go through the `schema-guardian` agent for review and require:

1. A version-bump assessment (patch / minor / major per the rules in [schema.md](schema.md))
2. Updated sample outputs in `README.md` and any affected tests
3. A `CHANGELOG.md` entry calling out the version implication

The `IndexBuilder` validates every built index against the schema before writing. If you change scanner output, expect the validation to catch mismatches early.

## Working on the agents

`.claude/agents/` and `.claude/commands/` codify the development workflow. Edits there should be deliberate:

- Agent prompts should give *insight*, not duplicate `README.md`
- New slash commands should chain existing agents rather than introduce parallel workflows
- `audit-spec`-style guardrails should reference observable behavior (tests, code), not frozen spec documents

If you fork the repo to build a different Laravel introspection tool, the agents may give you a useful starting point — they're intentionally generic about Laravel and specific about Atlas.

## Style

- `declare(strict_types=1);` at the top of every PHP file
- PHPDoc on every public method, especially structured return types
- Comments only when the WHY isn't obvious from the code — no narration of the WHAT
- One scanner per Laravel primitive; resist merging scanners even when they share visitors
- Cite the relevant schema section in commit messages when changing scanner output: `feat(observers): emit hooks alphabetically (cites $defs/observer)`

## Releasing

The release workflow is the `/prep-release` slash command:

1. Bumps `IndexBuilder::ATLAS_VERSION` (and `composer.json` if a version field is present)
2. Drafts a CHANGELOG entry from the Unreleased section
3. Verifies the toolchain is green
4. Suggests a git tag command

Tag, push, and create the GitHub release manually after reviewing the draft notes.
