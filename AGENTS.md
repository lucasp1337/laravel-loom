# AGENTS.md

Loom: static analyzer for Laravel wiring that emits a deterministic JSON index. PHP 8.3+, Laravel 12+, `nikic/php-parser` only. How it works: `docs/contributing/architecture.md`.

## Verify

Use the `verify` skill. All checks green before every commit.

## Hard rules

- Static only: no runtime container, no reflection of the scanned app, no `artisan event:list`.
- Never drop silently: an unreadable form becomes an `unresolved_*` entry with a reason.
- Never guess Laravel behaviour: verify against framework source or laravel.com.
- Schema changes go through `schema-guardian` (`change-schema` skill).
- No new commands, flags or output formats without an issue.
- Out of scope: models, migrations, policies, runtime tracing, code-quality metrics. Unsure, ask.
- DTOs between components, never associative arrays.
- Docs: one home per fact; generated or tested, never restated. Don't explain Laravel mechanics. The code wins over docs.
- No new dependency without justification.

## Where detail lives

| Topic | Home |
|---|---|
| Per-class behaviour | class docblock |
| AST and visitor rules | `ast-specialist` agent |
| Procedures | skills: `verify`, `change-schema`; command `/add-scanner` |
| Decisions | `docs/contributing/adr/` |
| Human setup | `CONTRIBUTING.md` |

## Commits and PRs

Conventional short subject, no body. No issue numbers in code comments. One compact CHANGELOG line per change. No AI or tool attribution anywhere.
