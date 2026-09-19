# Ask an agent about your events

By the end of this page, an AI agent in your editor can answer "what happens when an order is placed?" by calling Loom instead of grepping your source.

You need a Laravel app with Loom installed as a dev dependency (`composer require lucasp1337/laravel-loom --dev`) and an MCP client that can launch a local command: Claude Code, Cursor, or anything else that speaks [MCP](https://modelcontextprotocol.io) over stdio. The examples use an order and checkout app.

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

The agent doesn't search your files. It calls `events-following` with `OrderPlaced` and gets back the handlers, what they dispatch, and the handlers of those events, as JSON:

```json
{
  "root": "App\\Events\\OrderPlaced",
  "depth": 3,
  "edges": [
    {
      "event": "App\\Events\\OrderPlaced",
      "handler": "App\\Listeners\\SendReceipt::handle",
      "handler_kind": "listener",
      "dispatches": [
        { "target": "App\\Events\\ReceiptSent", "kind": "event", "confidence": "high", "file": "app/Listeners/SendReceipt.php", "line": 7 },
        { "target": "App\\Jobs\\SendMail", "kind": "job", "confidence": "high", "file": "app/Listeners/SendReceipt.php", "line": 9 }
      ]
    }
  ],
  "events_reached": ["App\\Events\\OrderPlaced"]
}
```

That answer has the shape the agent turns into prose: `SendReceipt` handles `OrderPlaced`, and from `SendReceipt.php` line 7 it fires `ReceiptSent`, and from line 9 it queues `SendMail`. Every claim carries a file and a line the agent can open next. Most clients show you which tool was called, so you can check the agent used the index and didn't guess.

The first time you run this on an app with no index, the first tool call takes as long as a scan, because the server scans for you (see [Where the index comes from](#where-the-index-comes-from)).

## What to ask, and which tool answers

The agent picks the tool from your question and from each tool's description. You can steer it by asking in the shape a tool answers. The full inputs and outputs are in the [tool reference](../reference/mcp-tools.md).

| You ask | The agent calls | What comes back |
| --- | --- | --- |
| "What does `POST /orders` trigger?" | `route-to-events` with `method` and `uri` | The route, the events and jobs its controller method dispatches, and each event's chain |
| "What does `OrderController::store` fire?" | `dispatches-from` (one hop) or `events-from-method` (transitive) | Targets with `kind`, `confidence`, file and line |
| "Who listens to `OrderPlaced`?" | `handlers-for` | Named listeners with `queued`, and closure listeners by file and line |
| "Where is `OrderPlaced` fired?" | `dispatch-sites-for` | Every file, line and method that dispatches it |
| "What happens after `OrderPlaced`, all the way down?" | `events-following` | The handler and dispatch chain, up to `depth` hops |
| "What breaks if I delete `SendReceipt`?" | `impact-of-change` | Which events it handles, which would lose their last handler, and notes on what Loom can't see |
| "Is there dead code in our events?" | `find-orphans` | Events nobody fires or handles, listeners that handle nothing |
| "What can't Loom trace?" | `find-unresolved-dispatches` | Dispatch sites built from variables, with the expression and reason |
| "Show me everything Loom found for jobs" | `list-entities` with `section` | One index section, verbatim, with a count |
| "Give me the full record for `SendReceipt`" | `get-entity` with `kind` and `fqcn` | That entity's record |

When you know the exact class name, say so. The tools match fully qualified names exactly, and an agent that has to guess `App\Events\OrderPlaced` from "the order event" may call `list-entities` first to find it.

## Where the index comes from

The server reads one file, the same `index.json` that `loom:scan` writes. It looks in `storage/loom/index.json` unless you point it elsewhere.

Set `LOOM_INDEX_PATH` in the environment the server starts in, or `index_path` in `config/loom.php`, and both `loom:scan` and `loom:mcp` use that file. The `--snapshot` flag overrides it for a single run.

| Flag | What it does |
| --- | --- |
| `--snapshot=PATH` | Serve the index at `PATH` and nothing else. The server never scans for you, because you own that file. |
| `--scan` | Run `loom:scan` before the server starts, so the answers match your working tree right now. |
| `--no-scan` | Never scan. If the index isn't there, the command exits with an error and doesn't start. |

With none of the flags, the server serves the default index and scans once, on the first tool call, if the file is missing. That's the right default for a laptop. For a shared machine or CI, where a silent scan on the first question is a surprise, use `--no-scan` so a missing index is an error you see:

```bash
php artisan loom:mcp --no-scan
```

```text
ERROR  No index at [/var/www/shop/storage/loom/index.json] and --no-scan is set. Run `php artisan loom:scan` first.
```

!!! warning "`--snapshot` and `--scan` can't be combined"
    `--scan` writes your configured index, not the file you name, so serving `--snapshot` afterwards would answer from a different file than the one just scanned. The command exits with `--scan writes the default index and cannot be combined with --snapshot.`

!!! warning "A missing `--snapshot` file isn't caught at startup"
    `--snapshot` turns auto-scan off. Without `--no-scan` the server still starts, and each tool call then fails with a generic "An internal server error occurred." that doesn't name the missing file. Add `--no-scan` when you pass `--snapshot` so a typo in the path fails immediately.

### Keep the index fresh

You don't restart the server after a scan. It compares the index file's modification time and size on every tool call and reloads when either changes. Run `php artisan loom:scan` in another terminal after you change code, and the agent's next question sees the new graph.

!!! warning "The graph is your last scan, not your working tree"
    The server never rescans on its own once an index exists. After you add a listener or a dispatch, the agent keeps answering from the old graph until you rescan. Start the server with `--scan` if you want a fresh graph every time the client launches it.

If a scan leaves a half-written or invalid file, a running server keeps answering from the last good copy. A server that starts against an invalid file has nothing to fall back on, and its tool calls fail with the same generic server error.

### Keep stdout clean

The client and server talk JSON-RPC over stdout. The server reserves stdout for that and nothing else, and `--scan` and the auto-scan run silently for the same reason.

!!! warning "Anything else on stdout breaks the connection"
    A stray `echo`, a `dd()` in a service provider, or a PHP deprecation notice printed to the terminal ends up in the middle of the JSON stream, and the client reports a parse error or drops the server. Log to a file, and set `display_errors=stderr` or `off` for the CLI. To debug, run `php artisan loom:mcp` in a terminal and watch for output that isn't JSON.

## How far the agent can see

Three tools follow chains: `events-following`, `events-from-method` and `route-to-events`. Each takes `depth`, the number of handler-to-dispatch hops to follow. It defaults to 3 and is clamped to 1 through 6, so asking for 0 gives 1 and asking for 99 gives 6.

At depth 1 you get an event's handlers and what those handlers fire, but not the handlers of the events they fire. Here's `OrderPlaced` at depth 1. `ReceiptSent` appears as a target, but nothing shows who handles it:

```json
{
  "root": "App\\Events\\OrderPlaced",
  "depth": 1,
  "edges": [
    {
      "event": "App\\Events\\OrderPlaced",
      "handler": "App\\Listeners\\SendReceipt::handle",
      "handler_kind": "listener",
      "dispatches": [
        { "target": "App\\Events\\ReceiptSent", "kind": "event", "confidence": "high", "file": "app/Listeners/SendReceipt.php", "line": 7 },
        { "target": "App\\Jobs\\SendMail", "kind": "job", "confidence": "high", "file": "app/Listeners/SendReceipt.php", "line": 9 }
      ]
    }
  ],
  "events_reached": ["App\\Events\\OrderPlaced"]
}
```

`events_reached` lists the events whose handlers were expanded, so here it holds only the root. Compare it with the `target` values to see what was left unexpanded.

!!! warning "The response doesn't say when depth cut it off"
    The chain tools return no `truncated` flag. A chain that ends at your depth looks the same as one that ended because nothing else listens. If an answer stops at an event that surely has listeners, ask the agent to call again with a larger `depth`.

A cycle, where `Ping` triggers `Pong` and `Pong` triggers `Ping`, doesn't loop forever. Each event is expanded once, so both appear once in `edges` and the walk stops. The response has no separate marker for the cycle. Use `loom:check` if you want cycles reported ([Gate your CI](gate-your-ci.md)).

Two granularity limits apply. A route is resolved to its controller method exactly. Listeners, observers and jobs resolve to the whole class, so asking what one of their methods dispatches returns everything the class dispatches. And `route-to-events` returns `"chain": null` with a note for a route whose action is a closure, because there's no controller method to follow.

## What Loom couldn't resolve

Loom reads source without running it, so a dispatch like `event($event)` has no class to name. Those never appear as targets. They're recorded separately, and `find-unresolved-dispatches` returns them:

```json
{
  "count": 1,
  "unresolved_dispatches": [
    { "file": "app/A.php", "line": 10, "expression": "dispatch($job)", "reason": "dynamic_class_name" }
  ]
}
```

Ask for this list before you trust an "is anything still listening to X?" answer. An event that looks unused may be dispatched from one of these lines. `impact-of-change` adds a note about the same gap whenever it reports on an event.

Every dispatch the agent does see carries a `confidence`, and today it is always `high`: the target was named in the source. `medium` and `low` are reserved for a future runtime overlay, so an agent shouldn't read `high` as "verified at runtime". The full list of what Loom can't follow is in [What Loom detects](../reference/what-loom-detects.md).

## When a tool returns nothing

A tool that finds nothing usually says so with an empty result, not an error, and the difference matters when the agent explains itself:

- An event nothing fires: `dispatch-sites-for` returns `"count": 0`.
- A class name that isn't in the index, or is misspelled: `events-following` returns an empty `edges` list, and `impact-of-change` returns `"entity": "unknown"` with a note that the class may be unscanned, dynamic or misspelled.
- A route that doesn't match the verb and URI exactly: `route-to-events` returns an error, `No route found for GET orders.`
- An unknown `kind` or `section`: an error that names the value it rejected.

If the agent reports "no handlers" for something you know is handled, check the name and rescan before trusting it. Then look at [Why was my code missed](why-was-my-code-missed.md) for shapes Loom doesn't read.

## Under the hood

The server is a local `laravel/mcp` server registered as `loom` when Loom boots, so any client that can run `php artisan loom:mcp` can use it. It loads the index through the same read-model as the [PHP API](../reference/php-api.md), and it only ever reads: it doesn't modify the index or your source. Tool responses are JSON text.
