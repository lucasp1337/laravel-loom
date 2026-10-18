---
template: home.html
hide:
  - navigation
  - toc
---

# Laravel Loom

## Understand it

- [What Loom sees](concepts/what-loom-sees.md): what a scan records and what it can't resolve.
- [The index](concepts/the-index.md): how to read one flow forward and backward.

## Use it

- [Browse the UI](guides/browse-the-ui.md): the read-only `/loom` dashboard and chain graph.
- [Ask an agent](guides/ask-an-agent.md): serve the index to an agent through the MCP server.
- [Use with Laravel Boost](guides/use-with-boost.md): register the MCP server next to Boost's.
- [Gate your CI](guides/gate-your-ci.md): diff and check the index on every pull request.
- [Why was my code missed?](guides/why-was-my-code-missed.md): trace a missing entry to its cause.

## Recipes

- [What runs when an event fires](recipes/event-chain.md)
- [What triggers a job](recipes/job-triggers.md)
- [Routes without auth](recipes/routes-without-auth.md)
- [Fail on new unresolved dispatches](recipes/new-unresolved-dispatches.md)

## Look things up

- [Commands](reference/commands.md): every `loom:*` command, flag and exit code.
- [Check rules and formats](reference/check-rules-and-formats.md): rules, exit codes and output formats.
- [GitHub Action](reference/action.md): scan, diff and check in a workflow.
- [MCP tools](reference/mcp-tools.md): the tools `loom:mcp` exposes.
- [Schema](reference/schema.md): the field-by-field index contract.
- [PHP API](reference/php-api.md): read the index as typed objects.
- [What Loom detects](reference/detection/index.md): tested examples for each primitive.
- [Scan configuration](reference/scan-config.md): what gets scanned.
- [Index, UI and MCP configuration](reference/ui-config.md): every other config key.
