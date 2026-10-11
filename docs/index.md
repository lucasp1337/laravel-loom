---
template: home.html
hide:
  - navigation
  - toc
---

# Laravel Loom

## Understand it

- [What Loom sees](concepts/what-loom-sees.md): every primitive it records, and the dispatches it admits it can't resolve.
- [The index](concepts/the-index.md): how to read one flow forward and backward.

## Use it

- [Browse the UI](guides/browse-the-ui.md): the read-only `/loom` dashboard and chain graph.
- [Ask an agent](guides/ask-an-agent.md): point an AI assistant at the index through the MCP server.
- [Use with Laravel Boost](guides/use-with-boost.md): register the MCP server next to Boost's.
- [Gate your CI](guides/gate-your-ci.md): diff and check the index on every pull request.
- [Why was my code missed?](guides/why-was-my-code-missed.md): from a missing entry back to its cause.

## Look things up

- [Commands](reference/commands.md): every `loom:*` command, flag and exit code.
- [Check rules and formats](reference/check-rules-and-formats.md): the rules `loom:check` runs and the output formats.
- [GitHub Action](reference/action.md): scan, diff and check in a workflow.
- [MCP tools](reference/mcp-tools.md): the tools `loom:mcp` exposes.
- [Schema](reference/schema.md): the field-by-field index contract.
- [PHP API](reference/php-api.md): read the index as typed objects.
- [What Loom detects](reference/what-loom-detects.md): the code shapes each scanner reads.
- [Scan configuration](reference/scan-config.md): which directories and route files are scanned.
- [UI configuration](reference/ui-config.md): the keys that control the browser UI.
