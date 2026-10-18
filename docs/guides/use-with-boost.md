# Use Loom with Laravel Boost

Teach agents in a [Laravel Boost](https://laravel.com/docs/boost) project when to call Loom, and register its server next to Boost's.

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

Boost registers only its own server, so add the `loom` entry to your MCP config yourself and restart the agent. The server needs `laravel/mcp`; [Ask an agent](ask-an-agent.md) covers Docker, paths and what to ask.

## What Boost picks up

Loom ships two files under `resources/boost/`, which Boost reads from each direct Composer dependency:

| File | Boost loads it as | It says |
| --- | --- | --- |
| `resources/boost/guidelines/core.blade.php` | A guideline, every session | When to call Loom, which tool answers what, and not to guess unresolved dispatches |
| `resources/boost/skills/loom/SKILL.md` | A skill, on demand | Workflows for impact analysis, orphans and tracing a route to its events |

Run `php artisan boost:install` (or `boost:update`) and select Loom when Boost lists third-party packages. The guideline tells agents to run `php artisan loom:scan` after changing events, listeners, jobs or routes.
