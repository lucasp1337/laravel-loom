# The MCP server

`loom:mcp` answers one question: **how does an AI agent query the event graph without grepping?**

It starts a local, read-only [MCP](https://modelcontextprotocol.io) server over the Loom index. Instead of an agent scanning your source for `event(`, `dispatch(`, and `handle(OrderPlaced`, it calls a tool and gets back a structured slice of the graph — FQCNs, dispatch sites, handler chains — from the pre-built `index.json`. The server runs embedded inside your app via `php artisan loom:mcp`; the `laravel/mcp` runtime auto-discovers it, so in a real Laravel app there is nothing to wire up.

## Running it

```bash
php artisan loom:mcp
```

The command resolves an index, then hands control to the stdio transport (JSON-RPC over stdin/stdout). By default it serves `storage/loom/index.json`: if that file is missing it runs `loom:scan` once to produce it, and it reloads automatically whenever the file changes on disk (watched by mtime — re-running `loom:scan` in another terminal is enough to refresh a running server).

| Flag             | Effect                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------- |
| `--snapshot=PATH`| Serve a specific index JSON instead of the default. The file is used as-is; auto-scan is off (you own it). |
| `--scan`         | Run a fresh `loom:scan` before serving.                                                                 |
| `--no-scan`      | Never auto-scan. Require the snapshot to already exist; error out if it doesn't.                        |

## Connecting an agent

Any stdio MCP client can launch the server. Point the client at `php`, pass it `artisan loom:mcp`, and run it from your project root so it scans the right app:

```json
{
  "mcpServers": {
    "loom": {
      "command": "php",
      "args": ["artisan", "loom:mcp"],
      "cwd": "/absolute/path/to/your/laravel-app"
    }
  }
}
```

That snippet is the shape used by Claude Code, Cursor, and most stdio clients — adapt the key names to your client. The server identifies itself as `loom` and exposes the tools below.

## Tools

Eleven read-only tools, grouped by what they walk. Every tool returns JSON as its text content.

### Lookups

| Tool                 | Args                | Answers                                                                 |
| -------------------- | ------------------- | ----------------------------------------------------------------------- |
| `list-entities`      | `section`           | What did the scanner find? Lists one index section verbatim, with a count. |
| `get-entity`         | `kind`, `fqcn`      | Fetch one entity's full record by kind (`event`, `listener`, `observer`, `job`, `mailable`, `notification`) and FQCN. |

`section` is one of `events`, `listeners`, `observers`, `model_events`, `jobs`, `routes`, `scheduled`, `mailables`, `notifications`, `unresolved_dispatches`, `closure_listeners`.

### Edges (one hop)

| Tool                  | Args          | Answers                                                                 |
| --------------------- | ------------- | ----------------------------------------------------------------------- |
| `dispatch-sites-for`  | `event_fqcn`  | Where is this event dispatched? Every source location (file, line, method). |
| `handlers-for`        | `event_fqcn`  | Who handles this event? Named listeners (class, method, queued) and closure listeners (file, line, queued). |
| `dispatches-from`     | `method_fqcn` | Given a method, what does it directly dispatch? Events and jobs with kind, confidence, file, line. |

`method_fqcn` accepts `Class::method`, `Class@method`, or a bare `Class` (every method).

### Chains (transitive)

| Tool                 | Args                          | Answers                                                                 |
| -------------------- | ----------------------------- | ----------------------------------------------------------------------- |
| `events-following`   | `event_fqcn`, `depth`         | Follow an event through its handlers, what they dispatch, and on down — the transitive chain rooted at the event. |
| `events-from-method` | `method_fqcn`, `depth`        | Transitive closure from a method: what it dispatches, then each event's chain. |
| `route-to-events`    | `method`, `uri`, `depth`      | Resolve an HTTP route (verb + URI) to the event chain its controller action triggers. |

`depth` is optional, bounded `1..6`, default `3`. `route-to-events` matches the verb case-insensitively and the URI exactly (leading slash optional).

### Analysis

| Tool                          | Args             | Answers                                                                 |
| ----------------------------- | ---------------- | ----------------------------------------------------------------------- |
| `impact-of-change`            | `fqcn`, `kind`   | Blast radius of removing or renaming a class — dispatchers, handlers, and the downstream chain. `kind` is `remove` (default) or `rename`. |
| `find-orphans`                | none             | Dead weight: orphan events (dispatched from nowhere and handled by nothing) and idle listeners (registered but handling no events). |
| `find-unresolved-dispatches`  | none             | Dispatch sites the scanner couldn't statically resolve, with the raw expression, reason, file, and line. |

## Notes and limits

- **Read-only.** The server never mutates the index or your source; it only reads the snapshot.
- **Granularity.** Dispatch is method-level only for routes. Listeners, observers, and jobs resolve at class level — a `dispatches-from` or `events-from-method` query for one of their methods reflects the whole class.
- **Freshness.** The graph is only as current as the snapshot. Re-run `loom:scan` (or start with `--scan`) to refresh; a running server picks up the change on the next tool call.

## See also

- [The index](the-index.md) — what the server reads.
- [Index PHP API](index-api.md) — the same read-model the tools are built on, for non-agent consumers.
- [Schema reference](schema.md) — the shape of every record the tools return.
