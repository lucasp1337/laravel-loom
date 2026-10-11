---
description: Scaffold a new Loom scanner end-to-end through the agent chain
argument-hint: <ScannerName>
---

You are scaffolding a new scanner for Laravel Loom. The scanner name is: $ARGUMENTS

## First: scope check

Before writing any code, figure out whether this is a redesign of an existing scanner or a new one:

1. List `src/Scanners/*.php` (e.g. with `Glob` or `ls`). That is the authoritative set of scanners in the codebase right now.
2. If `$ARGUMENTS` matches an existing file (with or without the `Scanner.php` suffix), this is a redesign. Confirm with the user before proceeding — they may have meant to extend, not replace.
3. If `$ARGUMENTS` is something new, surface that this expands Loom's scope. Loom is deliberately narrow (events, listeners, observers, dispatches). New primitives — container bindings, scheduler entries, broadcast channels, notifications, mailables — have historically been declined. Confirm with the user before designing.

Also check `docs/contributing/scanners-internals.md` and `docs/reference/what-loom-detects.md` for the corresponding behavior notes when a scanner exists.

## If scope check passes, run the chain

Delegate to each agent in order. Wait for completion before invoking the next.

### Step 1 — Design

Invoke `scanner-architect`:

> Design the `$ARGUMENTS` scanner per `AGENTS.md` and `docs/contributing/architecture.md`. Add a section for it to `docs/contributing/scanners-internals.md`, mirroring the existing per-scanner sections (What it detects, Output, Expected behavior), and put consumer-facing limitations in `docs/reference/what-loom-detects.md` and `docs/guides/why-was-my-code-missed.md`.

Read the design doc back to the user. Pause for confirmation if anything looks off.

### Step 2 — Schema review

Invoke `schema-guardian`:

> Review the schema contribution proposed in the `$ARGUMENTS` section of `docs/contributing/scanners-internals.md`. Confirm it fits the existing `schema/loom-index.schema.json` or specify what additions are needed and what version bump they imply.

If schema-guardian requires changes, apply them before continuing.

### Step 3 — Implementation

Invoke `ast-specialist`:

> Implement the `$ARGUMENTS` scanner at `src/Scanners/$ARGUMENTS.php` per the design in the `$ARGUMENTS` section of `docs/contributing/scanners-internals.md`. Follow the three-concern separation: discovery, parsing, emission. Register the scanner with `IndexBuilder`.

### Step 4 — Tests

Invoke `test-engineer`:

> Write tests for `$ARGUMENTS` per the test plan in the `$ARGUMENTS` section of `docs/contributing/scanners-internals.md`. Cover the visitor unit, scanner integration, and end-to-end layers. Add or extend fixture apps under `tests/Fixtures/` as needed.

### Step 5 — Quality

Invoke `quality-inspector`:

> Review `$ARGUMENTS` and its tests. Run `/run-checks`. Block on any failures.

### Step 6 — Docs

Invoke `doc-writer`:

> Update README sample output if `$ARGUMENTS` adds a new section. Add a CHANGELOG entry under [Unreleased].

## Final report

Summarize:

- Files created or modified
- Schema changes and version implications
- Test fixtures added
- CHANGELOG entry
- Any remaining `TODO` or `@throws` notes

If any step failed, stop and report — do not proceed past a failed step.
