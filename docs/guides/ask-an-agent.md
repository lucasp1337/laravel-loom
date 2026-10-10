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

You now have a server named `loom` that the client starts on demand and talks to over stdin and stdout. Use an absolute path to `artisan` so it works no matter which directory the client launches from.

!!! warning "Start the server from your app, not a wrapper"
    If PHP runs in Docker or Sail, point `command` at whatever runs `php artisan loom:mcp` inside the container, and make sure it doesn't allocate a terminal or print a banner. Anything extra on stdout breaks the connection (see [Keep stdout clean](#keep-stdout-clean)).

## Ask a question

Restart the client so it picks up the server, then ask something the index can answer:

```text
What happens when an order is placed?
```

The agent doesn't search your files. It calls `events-following` with `OrderPlaced` and gets back the handlers, what they dispatch, and the handlers of those events as JSON: `SendReceipt` handles `OrderPlaced`, and from `SendReceipt.php` line 7 it fires `ReceiptSent` and from line 9 queues `SendMail`. Every claim carries a file and line the agent can open next, and most clients show which tool was called, so you can check it used the index.

On an app with no index the first tool call takes as long as a scan, because the server scans for you (see [Where the index comes from](#where-the-index-comes-from)).

## What to ask, and which tool answers

The agent picks the tool from your question and each tool's description. Inputs and outputs are in the [tool reference](../reference/mcp-tools.md).

| You ask | The agent calls |
| --- | --- |
| "What does `POST /orders` trigger?" | `route-to-events` |
| "What does `OrderController::store` fire?" | `dispatches-from` (one hop) or `events-from-method` (transitive) |
| "Who listens to `OrderPlaced`?" | `handlers-for` |
| "Where is `OrderPlaced` fired?" | `dispatch-sites-for` |
| "What happens after `OrderPlaced`, all the way down?" | `events-following` |
| "What breaks if I delete `SendReceipt`?" | `impact-of-change` |
| "Is there dead code in our events?" | `find-orphans` |
| "What can't Loom trace?" | `find-unresolved-dispatches` |
| "Show me everything Loom found for jobs" | `list-entities` |
| "Give me the full record for `SendReceipt`" | `get-entity` |

When you know the exact class name, say so: the tools match fully qualified names exactly, and an agent guessing `App\Events\OrderPlaced` from "the order event" may call `list-entities` first.

## Where the index comes from

The server reads one file, the `index.json` that `loom:scan` writes, from `storage/loom/index.json` unless `LOOM_INDEX_PATH` or `index_path` in `config/loom.php` points elsewhere.

| Flag | What it does |
| --- | --- |
| `--snapshot=PATH` | Serve the index at `PATH` and nothing else. The server never scans, because you own that file. |
| `--scan` | Run `loom:scan` before the server starts, so answers match your working tree. |
| `--no-scan` | Never scan. If the index is missing the command exits with an error. |

With no flag the server serves the default index and scans once, on the first tool call, if the file is missing. That suits a laptop. On a shared machine or CI use `--no-scan`, so a missing index is an error you see:

```bash
php artisan loom:mcp --no-scan
```

!!! warning "`--snapshot` and `--scan` can't be combined"
    `--scan` writes the configured index, not the file you name. The command exits with `--scan writes the default index and cannot be combined with --snapshot.`

!!! warning "A missing `--snapshot` file isn't caught at startup"
    `--snapshot` turns auto-scan off, so without `--no-scan` the server starts and every tool call fails with a generic "An internal server error occurred." Add `--no-scan` with `--snapshot` so a typo fails immediately.

### Keep the index fresh

The server compares the index file's modification time and size on every call and reloads when either changes, so a `php artisan loom:scan` in another terminal is picked up without a restart. It never rescans on its own once an index exists; start it with `--scan` for a fresh graph on every launch. If a scan leaves an invalid file, a running server keeps answering from the last good copy.

### Keep stdout clean

Client and server talk JSON-RPC over stdout, so `--scan` and the auto-scan run silently.

!!! warning "Anything else on stdout breaks the connection"
    A stray `echo`, a `dd()` in a service provider, or a PHP deprecation notice ends up in the JSON stream and the client reports a parse error. Log to a file and set `display_errors=stderr` or `off` for the CLI. To debug, run `php artisan loom:mcp` in a terminal and look for output that isn't JSON.

## How far the agent can see

`events-following`, `events-from-method` and `route-to-events` take `depth`, the number of handler-to-dispatch hops. It defaults to 3 and is clamped to 1 through 6. At depth 1 an event's handlers and what they fire are returned, but not the handlers of those events. The response carries `truncated` (`true` when `depth` cut a chain short), `cycles` (events revisited on a path) and `events_reached` (events whose handlers were expanded; a target missing from it was left unexpanded). A cycle does not loop: each event is expanded once. For true dispatch cycles use `loom:check` ([Gate your CI](gate-your-ci.md)).

A route resolves to its controller method exactly. Listeners, observers and jobs resolve to the whole class. A closure route returns `"chain": null` with a note.

## What Loom couldn't resolve

A dispatch like `event($event)` has no class to name, so it never appears as a target. It is recorded in `unresolved_dispatches`, and `find-unresolved-dispatches` returns it with the file, line, expression and reason. Ask for this list before trusting "is anything still listening to X?": an event that looks unused may be fired from one of those lines. `impact-of-change` adds a note about the same gap.

Every dispatch carries a `confidence`, always `high` today: the target was named in the source, not verified at runtime. What Loom can't follow is listed in [What Loom detects](../reference/what-loom-detects.md).

## When a tool returns nothing

An empty result is usually not an error: an unfired event gives `"count": 0`, and a misspelled or unscanned class gives empty `edges` or `"kind": "unknown"` (see [responses](../reference/mcp-tools.md)). If the agent reports "no handlers" for something you know is handled, check the name and rescan, then see [Why was my code missed](why-was-my-code-missed.md).
