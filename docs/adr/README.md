# Architecture Decision Records

This directory holds load-bearing design decisions for Loom. Each ADR captures
*why* a particular path was taken — usually with two or three plausible
alternatives ruled out — so future contributors don't have to re-litigate the
same trade-off.

## What goes in an ADR vs. elsewhere

- **ADR** (a file in this directory) — a decision that constrains how something
  is built. Opaque-leaf vendor strategy, eager filesystem walk vs. Composer
  autoload, schema versioning policy. Short, focused, *immutable* once
  `Accepted`.
- **Reference docs (`docs/scanners/*.md`, `docs/support/*.md`,
  `docs/architecture.md`, `docs/schema.md`)** — *what* the code does today.
  Drifts freely with the code, same lifecycle as the source it documents.
- **CHANGELOG** — what shipped, when, in which version.

If a doc is describing current behaviour and would need to be edited on every
refactor, it's a reference doc, not an ADR.

## Conventions

- **Filename**: `NNNN-kebab-case-title.md`, zero-padded four-digit serial.
- **Status**: `Proposed` → `Accepted` → (later) `Superseded by NNNN` or
  `Deprecated`. Accepted ADRs are not edited — write a new ADR that supersedes
  the old one and update the old one's status line.
- **Sections**: `Status`, `Context`, `Decision`, `Consequences`, optionally
  `Alternatives considered`.
- **Length**: brief. A reader should be able to skim one in 60 seconds.
- **Cross-references**: link from the ADR to relevant reference docs; link
  back from reference docs to the ADR for the "why".

## Index

- [0001 — ClassHierarchyResolver](0001-class-hierarchy-resolver.md) — opaque
  leaves, eager filesystem walk, class-graph only.
- [0002 — ScheduleScanner](0002-schedule-scanner.md) — hybrid discovery,
  normalised cron, opaque constraints.
- [0003 — Mailables + Notifications](0003-mailables-notifications.md) —
  separate sections, shared dispatch-site machinery.
- [0004 — Sub-minute frequencies](0004-sub-minute-frequencies.md) — structured
  `frequency` object; cron stays null for sub-minute helpers.
