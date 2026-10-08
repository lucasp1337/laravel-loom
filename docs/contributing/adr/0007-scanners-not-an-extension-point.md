# ADR 0007 — Scanners are internal in 1.0: no third-party scanner support

**Status**: Proposed (2026-10-08)

**Reference**: [`docs/reference/php-api.md`](../../reference/php-api.md) for the
public PHP API this decision shapes.

## Context

`Contracts\Scanner` is a one-method interface, so it looks like an extension
point. Whether it is one decides what we must freeze for 1.0. Today:

- **No registration hook.** `DefaultScanners::all()` hard-codes the nine scanners
  and `loom:scan` builds its `IndexBuilder` from that list. Nothing reads config
  or a container tag. A third party would have to subclass the command or
  build their own `IndexBuilder`.
- **Sections are a closed set.** `IndexBuilder::build()` throws `Scanner returned
  unknown section` for any key outside the `Sections` enum (underscore-prefixed
  keys excepted), and the JSON schema sets `additionalProperties: false`. A
  third-party scanner can only append to existing sections, never add one.
- **Output is coupled to internals.** Entries are `Dto\*Entry` objects that
  `IndexSerializer` maps by section, plus the `_dispatch_sites` side channel that
  only the cross-link phases understand. Third-party output would have to use
  those internal shapes.
- **Cross-linking is hard-wired.** `CrossLinker` runs a fixed phase list over
  known sections; a custom entry gets no `dispatched_from` / `handled_by`.
- **Everything downstream is closed too.** `loom:diff` specs, the MCP tools and
  the UI section registry all enumerate the known sections.

So the contract is a seam between `IndexBuilder` and our own scanners, not a
plugin API. Supporting plugins would mean adding the hook and also opening the
section list, schema, serializer, cross-link, diff, MCP and UI layers.

## Decision (recommended)

Third-party scanners are **not supported in 1.0**.

- `Contracts\Scanner`, every scanner, visitor, `Dto\*`, `IndexBuilder` and
  `DefaultScanners` are `@internal`.
- The public PHP API is the read side only: `Index`, `IndexLoader`,
  `IndexLoadException`, the `Index\Model\` value objects and their enums.
- A Pest arch test requires every class under `src/` to be either `@api` and
  listed in `php-api.md`, or `@internal`.

Extending detection means contributing a scanner to this repository. Revisit
after 1.0 if there is demand; a plugin API would be a new ADR and a minor release.

## Consequences

**Good:**

- The scanner contract, DTOs and visitors stay free to change in minor releases.
- The stable surface is small: the schema and the read model that mirrors it.
- Docs stop implying an extension point that does not exist.

**Costs:**

- Teams with house-specific dispatch patterns cannot plug in their own detection;
  they must send a patch upstream or fork.

## Alternatives considered

1. **Support third-party scanners.** Freeze `Scanner`, add a registration hook
   (config list of class names or a container tag read by `DefaultScanners`).
   Rejected for 1.0: the hook is the cheap part. A scanner that adds no sections
   is of limited use, and one that adds sections needs an open schema, a
   registered serializer, cross-link participation and diff/MCP/UI support.
   Freezing the contract before that design exists would lock in the wrong shape.
2. **Leave it undocumented.** Rejected: any class is then de facto public, which
   is the problem the public API definition exists to remove.
