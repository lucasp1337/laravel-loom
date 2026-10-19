---
name: change-schema
description: Change the shape of the emitted index (new section, new or renamed field, enum value, unresolved reason). Use whenever schema/loom-index.schema.json or the Field/Sections/MetaField enums would change.
---

# Change the schema

`schema/loom-index.schema.json` is the contract. `schema-guardian` reviews every change and has veto power; start by invoking it (`.claude/agents/schema-guardian.md`). Background: CONTRIBUTING.md "Working on the schema" and `docs/reference/schema.md` (version-bump rules). Just + Docker only.

1. Assess the version bump: patch, minor or major per `docs/reference/schema.md`. Additive optional fields are non-breaking.
2. Edit `schema/loom-index.schema.json`.
3. Mirror it in the enums: `src/Index/Field.php` (also `Sections`, `MetaField` for new sections or meta). `tests/Unit/Index/FieldSchemaParityTest.php` fails when they diverge.
4. Update the scanner DTO in `src/Dto/`, `src/Index/Model/` and `Hydrate` for a new entry field (see "Where does X go?" in CONTRIBUTING.md); a new section also needs `SectionRegistry` and `DefaultScanners`.
5. A new unresolved reason needs a `UnresolvedReason` case with `summary()`, the schema description (a test keeps them equal) and an example case in `docs/examples/<primitive>/` with a `✗ reason` marker (`tests/Docs/ExamplesTest.php` fails on a reason with none).
6. Regenerate the reference pages: `just docs-generate`. Never edit inside `<!-- generated:NAME -->` blocks.
7. If MCP tool output changes, regenerate `tests/Fixtures/mcp-golden.json` deliberately with `just golden` and review the diff.
8. Refresh README sample output from a real scan (`just scan-json`) if affected; never hand-edit it.
9. Add one compact CHANGELOG line with the version implication.
10. Run the `verify` skill (`just verify`).
