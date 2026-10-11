# ADR 0007 — Scanners are internal in 1.0: no third-party scanner support

**Status**: Accepted (2026-10-08)

The public API this shapes is in [PHP API](../../reference/php-api.md).

## Context

`Contracts\Scanner` is a one-method interface, so it looks like an extension point. It is not:

- There is no registration hook: `DefaultScanners::all()` hard-codes the scanners.
- Sections are a closed set. `IndexBuilder::build()` throws on an unknown section (underscore-prefixed excepted) and the schema sets `additionalProperties: false`, so a scanner can only append to existing sections.
- Output is coupled to internals: `Dto\*Entry` objects mapped by section, and the `_dispatch_sites` side channel only the cross-link phases understand.
- Cross-linking, `loom:diff` specs, MCP tools and the UI section registry all enumerate the known sections.

Supporting plugins means the hook plus opening the section list, schema, serializer, cross-link, diff, MCP and UI.

## Decision

Third-party scanners are not supported in 1.0.

- `Contracts\Scanner`, every scanner, visitor, `Dto\*`, `IndexBuilder` and `DefaultScanners` are `@internal`.
- The public PHP API is the read side: `Index`, `IndexLoader`, `IndexLoadException` and the `Index\Model\` objects and enums.
- An arch test requires every class under `src/` to be `@api` and listed in the PHP API page, or `@internal`.

Extending detection means contributing a scanner here. A plugin API after 1.0 would be a new ADR and a minor release.

## Rejected

- Freezing `Scanner` and adding a hook now. The hook is the cheap part; a scanner that adds no section is of limited use, and one that does needs the rest opened up. Freezing before that design exists locks in the wrong shape.
- Leaving it undocumented, which makes every class de facto public.

## Consequences

- Scanner contract, DTOs and visitors change freely in minor releases; the stable surface is the schema and its read model.
- Teams with house-specific dispatch patterns send a patch or fork.
