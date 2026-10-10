# Architecture Decision Records

This directory holds load-bearing design decisions: why a path was taken, with the alternatives ruled out.

## ADR or reference

- **ADR**: a decision that constrains how something is built. Short and immutable once `Accepted`.
- **Reference** (scanner and visitor docblocks, [class hierarchy](../class-hierarchy.md), [architecture](../architecture.md), the generated [schema](../../reference/schema.md) page): what the code does today. It changes with the code.
- **CHANGELOG**: what shipped, in which version.

A document that would be edited on every refactor is a reference, not an ADR.

## Conventions

- File name `NNNN-kebab-case-title.md`.
- Status goes `Proposed`, `Accepted`, then `Superseded by NNNN` or `Deprecated`. An accepted ADR is not edited; write a new one that supersedes it.
- Sections: `Status`, `Context`, `Decision`, `Consequences`, optionally `Rejected`. Keep it skimmable in a minute.

## Index

- [0001 — ClassHierarchyResolver](0001-class-hierarchy-resolver.md) — opaque
  leaves, eager filesystem walk, class-graph only.
- [0002 — ScheduleScanner](0002-schedule-scanner.md) — hybrid discovery,
  normalised cron, opaque constraints.
- [0003 — Mailables + Notifications](0003-mailables-notifications.md) —
  separate sections, shared dispatch-site machinery.
- [0004 — Sub-minute frequencies](0004-sub-minute-frequencies.md) — structured
  `frequency` object; cron stays null for sub-minute helpers.
- [0005 — Index read model](0005-index-read-model.md) — read model decoupled
  from scanner DTOs, hydrated from the schema shape.
- [0006 — Benchmark suite](0006-benchmark-suite.md) — gate on deterministic
  counts, not wall time; deterministic generator over committed fixtures.
- [0007 — Scanners are internal](0007-scanners-not-an-extension-point.md) —
  no third-party scanner support in 1.0; public PHP API is the read side only.
