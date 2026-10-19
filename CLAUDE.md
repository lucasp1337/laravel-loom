# CLAUDE.md

This project uses **AGENTS.md** as the canonical agent orientation document. Read that file first.

Key references:
- `AGENTS.md` — hard rules and where detail lives
- `docs/contributing/architecture.md` — build and read side, scanner contract, visitor conventions, cross-link pass
- `docs/contributing/add-a-scanner.md` — how to add a scanner, with a checklist
- `docs/reference/schema.md` — JSON schema reference (companion to `schema/loom-index.schema.json`)
- `docs/examples/` — tested example corpus, rendered to `docs/reference/detection/`; the consumer view of scanner behavior. The AST shapes each scanner and visitor matches are in their class docblocks
- `CONTRIBUTING.md` — toolchain, Docker workflow, docs rules
- `.claude/skills/verify`, `.claude/skills/change-schema` — check and schema-change procedures
- `.claude/agents/ast-specialist.md` — AST and visitor rules
- `.claude/agents/issue-worker.md` — implement one GitHub issue end to end, through to an open PR
