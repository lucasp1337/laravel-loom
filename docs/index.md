# Laravel Loom

Loom is a static analyzer that maps a Laravel app's events, listeners, jobs, mailables, notifications, schedules and routes into a JSON index, a browser UI and an MCP server. It reads your source, so nothing is booted and nothing is traced at runtime.

New here? [Getting started](getting-started.md) takes a few minutes and ends with you looking at where `OrderPlaced` goes in your own app.

## Understand it

- [What Loom sees](concepts/what-loom-sees.md): every primitive it records, and the dispatches it admits it can't resolve.
- [The index](concepts/the-index.md): how to read one flow forward and backward.

## Use it

- [Browse the UI](guides/browse-the-ui.md)
- [Ask an agent](guides/ask-an-agent.md)
- [Gate your CI](guides/gate-your-ci.md)
- [Why was my code missed?](guides/why-was-my-code-missed.md)

## Look things up

- [Commands](reference/commands.md)
- [Check rules and formats](reference/check-rules-and-formats.md)
- [GitHub Action](reference/action.md)
- [MCP tools](reference/mcp-tools.md)
- [Schema](reference/schema.md)
- [PHP API](reference/php-api.md)
- [Upgrading](upgrading.md)
