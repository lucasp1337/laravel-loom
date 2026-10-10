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

| ADR | Decision |
| --- | --- |
| [0001](0001-class-hierarchy-resolver.md) | ClassHierarchyResolver: opaque leaves, eager walk, class graph |
| [0002](0002-schedule-scanner.md) | ScheduleScanner: hybrid discovery, normalised cron, opaque constraints |
| [0003](0003-mailables-notifications.md) | Mailables and notifications: separate sections, shared dispatch-site machinery |
| [0004](0004-sub-minute-frequencies.md) | Structured `frequency` for sub-minute helpers |
| [0005](0005-index-read-model.md) | Read model decoupled from scanner DTOs |
| [0006](0006-benchmark-suite.md) | Benchmarks gate on counts, not wall time |
| [0007](0007-scanners-not-an-extension-point.md) | Scanners are internal in 1.0 |
