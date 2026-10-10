# ADR 0005 — Index read model: decoupled from scanner DTOs, hydrated from the schema shape

**Status**: Accepted (2026-06-07)

The public API is in [PHP API](../../reference/php-api.md).

## Context

The UI (#19), the MCP server (#20) and custom tooling need to read an index from PHP. Two typed surfaces existed: the `Dto\*Entry` classes visitors emit, or a new read model hydrated from the written `index.json`. Whatever consumers depend on becomes a stable API and decides whether the scan pipeline can keep moving.

## Decision

1. **A dedicated read model in `Index\Model\`.** `final readonly` value objects (`Event`, `Listener`, `Job`, plus shared `Dispatch`, `DispatchSite`, `QueueConfig`, `Handle`, `Handler`). Scanner DTOs stay internal build inputs; the two never merge, because DTOs are shaped for collection during traversal and the read model for consumption.
2. **Hydrate from the schema shape.** `fromArray()` factories and `IndexLoader` consume the same arrays `Index::toArray()` emits and the schema defines, so any written index loads without scanners present.
3. **Fields mirror the schema, camelCased, enums typed.** `dispatched_from` is `dispatchedFrom`; enum-valued fields hydrate into the `Index\` enums plus `Confidence`. Nullability matches the schema.

## Rejected

- Exposing `Dto\*Entry`: welds the API to traversal internals, and needs an in-process build.
- Raw arrays: no type checking for consumers.
- Generating the model from the schema: a codegen step for a small, stable model that a parity test already guards.

## Consequences

- Visitors, DTOs, scanners and cross-link phases refactor freely while the JSON validates.
- One contract, the schema, with `FieldSchemaParityTest` keeping the model aligned.
- Field declarations are duplicated across the model and the DTOs, so an additive schema change touches the schema, the model and, where relevant, a DTO. The parity test catches the first two; the DTO half is reviewed.
