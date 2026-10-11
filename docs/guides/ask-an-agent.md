# Ask an agent about your events

By the end of this page, an AI agent in your editor can answer "what happens when an order is placed?" by calling Loom instead of grepping your source.

You need a Laravel app with Loom installed as a dev dependency (`composer require lucasp1337/laravel-loom --dev`), `laravel/mcp` (`composer require laravel/mcp --dev`; without it `loom:mcp` exits 1 with an install hint), and an MCP client that can launch a local command: Claude Code, Cursor, or anything else that speaks [MCP](https://modelcontextprotocol.io) over stdio. The examples use an order and checkout app.

## Register the server

`loom:mcp` starts a read-only server that answers questions from your index. Your client launches it, so registering it means telling the client which command to run.

For Claude Code, add a `.mcp.json` at the root of your project:

```json
{
  "mcpServers": {
    "loom": {
      "command": "php",
      "args": ["/var/www/shop/artisan", "loom:mcp"]
    }
  }
}
```

Cursor reads the same shape from `.cursor/mcp.json`. Claude Code can also write the entry for you:

```bash
claude mcp add loom -- php /var/www/shop/artisan loom:mcp
```

Use an absolute path to `artisan` so it works no matter which directory the client launches from.

!!! warning "Start the server from your app, not a wrapper"
    If PHP runs in Docker or Sail, point `command` at whatever runs `php artisan loom:mcp` inside the container, and make sure it doesn't allocate a terminal or print a banner. Anything extra on stdout breaks the connection (see [Keep stdout clean](#keep-stdout-clean)).

## Ask a question

Restart the client so it picks up the server, then ask something the index can answer:

```text
What happens when an order is placed?
```

The agent doesn't search your files. It calls `events-following` with `OrderPlaced` and gets back the handlers, what they dispatch, and the handlers of those events, as JSON:

```json
{
  "root": "App\\Events\\OrderPlaced",
  "depth": 3,
  "edges": [
    {
      "event": "App\\Events\\OrderPlaced",
      "handler": "App\\Listeners\\SendOrderConfirmation::handle",
      "handler_kind": "listener",
      "dispatches": [
        {
          "target": "App\\Jobs\\SendReceipt",
          "kind": "job",
          "confidence": "high",
          "file": "app/Listeners/SendOrderConfirmation.php",
          "line": 12
        }
      ]
    },
    {
      "event": "App\\Events\\OrderPlaced",
      "handler": "app/Providers/EventServiceProvider.php:13",
      "handler_kind": "closure",
      "dispatches": []
    }
  ],
  "events_reached": [
    "App\\Events\\OrderPlaced"
  ],
  "cycles": [],
  "truncated": false
}
```

The agent turns that into prose: `SendOrderConfirmation` handles `OrderPlaced` and, at line 12, queues `SendReceipt`; a closure registered at `EventServiceProvider.php` line 13 also handles it and dispatches nothing. Every claim carries a file and a line the agent can open next. Most clients show which tool was called, so you can check the agent used the index and didn't guess.

On an app with no index the first tool call takes as long as a scan, because the server scans for you (see [Where the index comes from](#where-the-index-comes-from)).

## What to ask, and which tool answers

The agent picks the tool from your question and from each tool's description, so you can steer it by asking in the shape a tool answers. Inputs and outputs are in the [tool reference](../reference/mcp-tools.md).

| You ask | The agent calls | What comes back |
| --- | --- | --- |
| "What does `POST /orders` trigger?" | `route-to-events` | The route, the events and jobs its controller method dispatches, and each event's chain |
| "What does `OrderController::store` fire?" | `dispatches-from` (one hop) or `events-from-method` (transitive) | Targets with `kind`, `confidence`, file and line |
| "Who listens to `OrderPlaced`?" | `handlers-for` | Named listeners with `queued`, and closure listeners by file and line |
| "Where is `OrderPlaced` fired?" | `dispatch-sites-for` | Every file, line and method that dispatches it |
| "What happens after `OrderPlaced`, all the way down?" | `events-following` | The handler and dispatch chain, up to `depth` hops |
| "What breaks if I delete `SendReceipt`?" | `impact-of-change` | The events it handles, which would lose their last handler, and notes on what Loom can't see |
| "Is there dead code in our events?" | `find-orphans` | Events nobody fires or handles, listeners that handle nothing |
| "What can't Loom trace?" | `find-unresolved-dispatches` | Dispatch sites built from variables, with the expression and reason |
| "List the jobs", "show `SendReceipt`" | `list-entities`, `get-entity` | One index section with a count; one entity's full record |

When you know the exact class name, say so: the tools match fully qualified names exactly, and an agent guessing `App\Events\OrderPlaced` from "the order event" may call `list-entities` first.

## Where the index comes from

The server reads one file, the `index.json` that `loom:scan` writes. It looks in `storage/loom/index.json` unless you point it elsewhere:

- Set `LOOM_INDEX_PATH`, or `index_path` in `config/loom.php`, and both `loom:scan` and `loom:mcp` use that file.
- Pass `--snapshot` to override it for a single run.

| Flag | What it does |
| --- | --- |
| `--snapshot=PATH` | Serve the index at `PATH` and nothing else. The server never scans, because you own that file. |
| `--scan` | Run `loom:scan` before the server starts, so answers match your working tree. |
| `--no-scan` | Never scan. If the index is missing the command exits with an error. |

With no flag the server serves the default index and scans once, on the first tool call, if the file is missing. That suits a laptop. On a shared machine or CI use `--no-scan`, so a missing index is an error you see:

```bash
php artisan loom:mcp --no-scan
```

```text
ERROR  No index at [/var/www/shop/storage/loom/index.json] and --no-scan is set. Run `php artisan loom:scan` first.
```

!!! warning "`--snapshot` and `--scan` can't be combined"
    `--scan` writes the configured index, not the file you name. The command exits with `--scan writes the default index and cannot be combined with --snapshot.`

!!! warning "A missing `--snapshot` file isn't caught at startup"
    `--snapshot` turns auto-scan off, so without `--no-scan` the server starts and every tool call fails with a generic "An internal server error occurred." Add `--no-scan` with `--snapshot` so a typo fails immediately.

### Keep the index fresh

You don't restart the server after a scan. It compares the index file's modification time and size on every tool call and reloads when either changes, so a `php artisan loom:scan` in another terminal is picked up by the agent's next question.

!!! warning "The graph is your last scan, not your working tree"
    The server never rescans on its own once an index exists. After you add a listener or a dispatch, the agent keeps answering from the old graph until you rescan. Start the server with `--scan` for a fresh graph each time the client launches it.

If a scan leaves an invalid file, a running server keeps answering from the last good copy. A server that starts against an invalid file has nothing to fall back on, and its tool calls fail with the generic server error.

### Keep stdout clean

Client and server talk JSON-RPC over stdout, so `--scan` and the auto-scan run silently.

!!! warning "Anything else on stdout breaks the connection"
    A stray `echo`, a `dd()` in a service provider, or a PHP deprecation notice ends up in the JSON stream and the client reports a parse error. Log to a file and set `display_errors=stderr` or `off` for the CLI. To debug, run `php artisan loom:mcp` in a terminal and look for output that isn't JSON.

## How far the agent can see

`events-following`, `events-from-method` and `route-to-events` take `depth`, the number of handler-to-dispatch hops to follow. It defaults to 3 and is clamped to 1 through 6, so asking for 0 gives 1 and asking for 99 gives 6. At depth 1 you get an event's handlers and what they fire, but not the handlers of those events.

The response tells you where it stopped:

- `truncated` is `true` when `depth` cut a chain short.
- `events_reached` lists the events whose handlers were expanded; a `target` missing from it was left unexpanded.
- `cycles` lists events revisited on a path. Each event is expanded once, so a cycle never loops. For true dispatch cycles use `loom:check` ([Gate your CI](gate-your-ci.md)).

!!! warning "Depth is per call"
    If an answer stops at an event that surely has listeners, check `truncated` and ask the agent to call again with a larger `depth`.

Two granularity limits apply. A route resolves to its controller method exactly; listeners, observers and jobs resolve to the whole class, so asking what one of their methods dispatches returns everything the class dispatches. And `route-to-events` returns `"chain": null` with a note for a route whose action is a closure, because there's no controller method to follow.

## What Loom couldn't resolve

A dispatch like `event($event)` has no class to name, so it never appears as a target. It is recorded in `unresolved_dispatches`, and `find-unresolved-dispatches` returns it:

```json
{
  "count": 1,
  "unresolved_dispatches": [
    {
      "file": "app/Services/Notifier.php",
      "line": 9,
      "expression": "event($eventClass)",
      "reason": "dynamic_class_name"
    }
  ]
}
```

Ask for this list before you trust an "is anything still listening to X?" answer: an event that looks unused may be fired from one of those lines. `impact-of-change` adds a note about the same gap.

### Confidence

Every dispatch carries a `confidence`, always `high` today: the target was named in the source. `medium` and `low` are reserved, so don't read `high` as "verified at runtime". What Loom can't follow is listed in [What Loom detects](../reference/what-loom-detects.md).

## When a tool returns nothing

An empty result is usually not an error, and the difference matters when the agent explains itself:

- An event nothing fires: `dispatch-sites-for` returns `"count": 0`.
- A class that isn't in the index, or is misspelled: `events-following` returns an empty `edges` list, and `impact-of-change` returns `"kind": "unknown"` with a note that the class may be unscanned, dynamic or misspelled.
- A route that doesn't match the verb and URI: `route-to-events` returns an error, `No route found for GET orders.`
- An unknown `kind` or `section`: an error that names the value it rejected.

If the agent reports "no handlers" for something you know is handled, check the name and rescan, then see [Why was my code missed](why-was-my-code-missed.md).
