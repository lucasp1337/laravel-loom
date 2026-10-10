# Use Loom with Laravel Boost

By the end of this page, agents in a [Laravel Boost](https://laravel.com/docs/boost) project know Loom exists, when to call it, and have the server registered.

You need Loom as a dev dependency (`composer require lucasp1337/laravel-loom --dev`), `laravel/mcp` (`composer require laravel/mcp --dev`), and Boost installed.

## What Boost picks up

Boost reads `resources/boost/` from each direct Composer dependency. Loom ships two files there:

| File | Boost treats it as | What it says |
| --- | --- | --- |
| `resources/boost/guidelines/core.blade.php` | A guideline, loaded every session | When to call Loom, which tool answers which question, and not to guess unresolved dispatches |
| `resources/boost/skills/loom/SKILL.md` | A skill, loaded on demand | Workflows for impact analysis, finding orphans, and tracing a route to its events |

Run `php artisan boost:install` (or `boost:update` later) and select Loom when Boost lists third-party packages. The guideline is written into each selected agent's guideline file, and the skill is installed for the agents you chose.

## Register the MCP server

Boost writes only its own `boost:mcp` entry. It does not register Loom's server, so add `loom:mcp` to your MCP config yourself. For a `.mcp.json`:

```json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "php",
      "args": ["artisan", "boost:mcp"]
    },
    "loom": {
      "command": "php",
      "args": ["artisan", "loom:mcp"]
    }
  }
}
```

Or from the command line:

```bash
claude mcp add loom -- php artisan loom:mcp
```

See [Ask an agent about your events](ask-an-agent.md) for other clients, Docker and Sail, and what to ask. Every tool's inputs are in the [MCP tools reference](../reference/mcp-tools.md).

## Keep the index current

The guideline tells agents to run `php artisan loom:scan` after changing events, listeners, jobs or routes. The server also scans for you when it finds no index.
