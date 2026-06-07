# ADR 0005 — Index read model: decoupled from scanner DTOs, hydrated from the schema shape

**Status**: Accepted (2026-06-07)

**Reference**: [`docs/index-api.md`](../index-api.md) for the public API and current
behaviour. This ADR captures only the load-bearing decisions.

## Context

Library consumers — the read-only browser UI (#19), the MCP server (#20), and
custom tooling — need to consume a Loom index from PHP. Two existing typed
surfaces could have served:

1. The `Lucasp\Loom\Dto\*Entry` classes that visitors emit and scanners consume.
2. A new read model hydrated from the written `index.json` shape.

The choice matters because whatever consumers depend on becomes a stable API we
must not break casually, and it determines whether the scan pipeline's internals
can keep moving freely.

## Decision

### 1. A dedicated read model in `Index\Model\`, not the scanner DTOs

Consumers get a new set of `final readonly` value objects under
`Lucasp\Loom\Index\Model\` (`Event`, `Listener`, `Job`, … plus shared `Dispatch`,
`DispatchSite`, `QueueConfig`, `Handle`, `Handler`). The scanner-side
`Dto\*Entry` classes stay internal build inputs and are not part of the consumer
API.

The two never merge. Scanner DTOs are shaped by what's convenient to collect
during AST traversal; the read model is shaped by what's convenient to consume.
Coupling them would freeze scanner internals against consumer expectations.

### 2. Hydrate from the JSON/array shape, not from scanner output

The read model's `fromArray()` factories — and `IndexLoader` — consume the same
associative-array shape that `Index::toArray()` emits and that
`schema/loom-index.schema.json` defines. The schema is the contract; the read
model is one side of it, the JSON file the other.

This means a consumer can load any `index.json` Loom ever wrote without the
scanners being present or even runnable, and the read model tracks the schema
rather than the scanners.

### 3. Fields mirror the schema, camelCased; strings become typed enums

Property names map 1:1 to schema keys with snake_case → camelCase
(`dispatched_from` → `dispatchedFrom`). Enum-valued schema fields hydrate into the
existing `Index\` enums (`ListenerRegistration`, `ObserverRegistration`,
`ScheduleKind`, `DispatchKinds`) plus a new `Confidence` enum. Optional/nullable
rules match the schema exactly (`overrides`/`channels` null when absent,
`queueConfig` null when not queued).

## Consequences

**Good:**

- The scan pipeline (visitors, DTOs, scanners, cross-link phases) can be
  refactored freely as long as the emitted JSON still validates. Consumers are
  insulated.
- One contract — the schema — governs both the on-disk JSON and the in-memory
  read model, so a `FieldSchemaParityTest`-style guarantee keeps them aligned and
  there is no second source of truth to drift.
- Consumers load a written index with zero scanner dependencies and get full IDE
  / PHPStan typing, including typed enums.

**Costs:**

- The read model duplicates field declarations that also exist (differently) on
  the scanner DTOs. Two parallel typed surfaces is more code than one shared one.
- Every additive schema change now touches three places: the schema, the read
  model, and (where relevant) a scanner DTO. The parity test catches the schema
  ↔ read-model half; the DTO half is reviewer-enforced.

## Alternatives considered

1. **Expose the `Dto\*Entry` classes as the consumer API.** Rejected: it welds
   the public API to scanner-internal shapes, so any traversal-driven refactor
   becomes a breaking change. It also requires a built `Index` in-process —
   consumers reading a written file off disk have no DTOs.
2. **Return raw associative arrays from `Index` (no value objects).** Rejected:
   pushes array-key guessing and `@var` hints onto every consumer and gives
   PHPStan nothing to check. The schema is documented but not type-enforced at the
   call site.
3. **Generate the read model from the JSON schema at build time.** Rejected for
   now: adds a codegen step and a tool dependency for a model that is small and
   stable enough to hand-write, and the parity test already guards drift.
