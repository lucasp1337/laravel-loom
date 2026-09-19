# MCP tools

Every tool the `loom` server exposes over `php artisan loom:mcp`, with its inputs and the shape of what it returns. For how to register the server and what to ask it, see [Ask an agent about your events](../guides/ask-an-agent.md).

All tools are read-only and return one JSON document as text. The examples below are real responses from a small order app in which `OrderController` fires `OrderPlaced`, `SendReceipt` handles it, and `SendReceipt` fires `ReceiptSent` and queues `SendMail`.

| Tool | Answers | Inputs |
| --- | --- | --- |
| [`list-entities`](#list-entities) | What did the scan find in one section? | `section` |
| [`get-entity`](#get-entity) | What is the full record for one class? | `kind`, `fqcn` |
| [`dispatch-sites-for`](#dispatch-sites-for) | Where is this event fired? | `event_fqcn` |
| [`handlers-for`](#handlers-for) | Who handles this event? | `event_fqcn` |
| [`dispatches-from`](#dispatches-from) | What does this method fire directly? | `method_fqcn` |
| [`events-following`](#events-following) | What does this event set off, transitively? | `event_fqcn`, `depth` |
| [`events-from-method`](#events-from-method) | What does this method set off, transitively? | `method_fqcn`, `depth` |
| [`route-to-events`](#route-to-events) | What does this HTTP route set off? | `method`, `uri`, `depth` |
| [`impact-of-change`](#impact-of-change) | What does removing or renaming this class affect? | `fqcn`, `kind` |
| [`find-orphans`](#find-orphans) | Which events and listeners are dead weight? | none |
| [`find-unresolved-dispatches`](#find-unresolved-dispatches) | Which dispatches couldn't Loom resolve? | none |

## Conventions

Class names are fully qualified and case-sensitive, written the way PHP writes them (`App\Events\OrderPlaced`). In JSON output the backslashes are escaped, so they appear as `App\\Events\\OrderPlaced`.

`depth` is an integer number of handler-to-dispatch hops, default `3`. Values outside `1` to `6` are clamped, not rejected. The response echoes the depth actually used.

A method reference (`method_fqcn`) is `Class::method`, `Class@method`, or a bare `Class` for every method it has.

Errors come back as a text message instead of JSON. Empty results are not errors, except where a tool below says otherwise.

Chain output always carries `truncated` (`true` when `depth` cut a chain short) and `cycles` (events revisited on a path). `events_reached` lists the events whose handlers were expanded, so a `target` that isn't in it was left unexpanded.

## Lookups

### `list-entities`

Lists one section of the index verbatim, with a count. Use it to find a class name before asking about it.

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `section` | string | yes | One of `events`, `listeners`, `observers`, `model_events`, `jobs`, `routes`, `scheduled`, `mailables`, `notifications`, `unresolved_dispatches`, `closure_listeners` |

```json
{
  "section": "jobs",
  "count": 1,
  "items": [
    {
      "fqcn": "App\\Jobs\\SendMail",
      "file": "app/Jobs/SendMail.php",
      "line": 6,
      "queued": true,
      "queue_config": null,
      "dispatched_from": [{ "file": "app/Listeners/SendReceipt.php", "line": 9, "method": "dispatch" }],
      "dispatches": [
        { "target": "App\\Events\\Pong", "kind": "event", "confidence": "high", "file": "app/Jobs/SendMail.php", "line": 15 }
      ]
    }
  ]
}
```

`items` has the same fields as that section of the [index](schema.md). An unknown `section` returns `Unknown section [widgets].`

### `get-entity`

Fetches one class's record by kind and name.

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `kind` | string | yes | One of `event`, `listener`, `observer`, `job`, `mailable`, `notification` |
| `fqcn` | string | yes | Fully qualified class name |

```json
{
  "kind": "listener",
  "fqcn": "App\\Listeners\\PingListener",
  "found": true,
  "entity": {
    "fqcn": "App\\Listeners\\PingListener",
    "file": "app/Listeners/PingListener.php",
    "line": 5,
    "handles": [{ "event": "App\\Events\\Ping", "method": "handle" }],
    "registration": "auto_discovered",
    "queued": true,
    "dispatches": [
      { "target": "App\\Events\\Pong", "kind": "event", "confidence": "high", "file": "app/Listeners/PingListener.php", "line": 7 }
    ]
  }
}
```

A missing class returns `No job found for App\Nope.` (with the kind you asked for), and an unknown `kind` returns `Unknown kind [route].`

`entity` is the raw index entry, snake_case, identical to what `list-entities` and `index.json` carry.

## Edges

One hop, no recursion.

### `dispatch-sites-for`

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `event_fqcn` | string | yes | The event class |

```json
{
  "event": "App\\Events\\OrderPlaced",
  "count": 1,
  "dispatch_sites": [{ "file": "app/Http/Controllers/OrderController.php", "line": 20, "method": "dispatch" }]
}
```

An event nothing fires returns `"count": 0` and an empty list. An empty `event_fqcn` returns `event_fqcn is required.`

### `handlers-for`

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `event_fqcn` | string | yes | The event class |

```json
{
  "event": "App\\Events\\ReceiptSent",
  "listeners": [{ "listener": "App\\Listeners\\ArchiveReceipt", "method": "handle", "queued": false }],
  "closure_listeners": [{ "file": "app/Providers/EventServiceProvider.php", "line": 30, "queued": true }]
}
```

Closure listeners have no class name, so they're identified by file and line.

### `dispatches-from`

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `method_fqcn` | string | yes | `Class::method`, `Class@method`, or a bare `Class` |

```json
{
  "method": "App\\Http\\Controllers\\OrderController::store",
  "count": 2,
  "dispatches": [
    { "target": "App\\Events\\OrderPlaced", "kind": "event", "confidence": "high", "file": "app/Http/Controllers/OrderController.php", "line": 20 },
    { "target": "App\\Jobs\\SendMail", "kind": "job", "confidence": "high", "file": "app/Http/Controllers/OrderController.php", "line": 22 }
  ]
}
```

Only routes resolve to an exact method. For a listener, observer or job, the answer covers the whole class.

## Chains

### `events-following`

Follows an event to its handlers, what they dispatch, and the handlers of those events, down to `depth`.

| Input | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `event_fqcn` | string | yes | none | The event to start from |
| `depth` | integer | no | `3` | Clamped to `1`-`6` |

```json
{
  "root": "App\\Events\\ReceiptSent",
  "depth": 3,
  "edges": [
    { "event": "App\\Events\\ReceiptSent", "handler": "App\\Listeners\\ArchiveReceipt::handle", "handler_kind": "listener", "dispatches": [] },
    {
      "event": "App\\Events\\ReceiptSent",
      "handler": "app/Providers/EventServiceProvider.php:30",
      "handler_kind": "closure",
      "dispatches": [
        { "target": "App\\Events\\Ping", "kind": "event", "confidence": "high", "file": "app/Providers/EventServiceProvider.php", "line": 32 }
      ]
    }
  ],
  "events_reached": ["App\\Events\\ReceiptSent", "App\\Events\\Ping"],
  "cycles": [],
  "truncated": false
}
```

Each edge is one handler of one event. `handler_kind` is `listener` or `closure`, and a closure handler is named `file:line`. Each event is expanded once, so a cycle doesn't repeat. An event that isn't in the index returns an empty `edges` list and no error.

### `events-from-method`

Starts from a method: what it dispatches, then the chain following each dispatched event.

| Input | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `method_fqcn` | string | yes | none | `Class::method`, `Class@method`, or a bare `Class` |
| `depth` | integer | no | `3` | Clamped to `1`-`6`, applied per chain |

The response is `{ "method", "dispatches", "chains" }`. `dispatches` is what [`dispatches-from`](#dispatches-from) returns, and `chains` holds one `events-following` result per dispatched event. An unknown method returns empty `dispatches` and `chains`.

### `route-to-events`

Resolves an HTTP route to its controller method, then behaves like `events-from-method`.

| Input | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `method` | string | yes | none | HTTP verb, case-insensitive |
| `uri` | string | yes | none | Matched exactly; leading slash optional |
| `depth` | integer | no | `3` | Clamped to `1`-`6` |

```json
{
  "route": {
    "method": "POST",
    "uri": "orders",
    "name": "orders.store",
    "controller_fqcn": "App\\Http\\Controllers\\OrderController",
    "controller_method": "store",
    "middleware": ["web"],
    "file": "routes/web.php",
    "line": 12
  },
  "chain": {
    "method": "App\\Http\\Controllers\\OrderController::store",
    "dispatches": ["..."],
    "chains": ["..."]
  }
}
```

`chain` holds what `events-from-method` returns. A route whose action is a closure returns the route with `"chain": null` and a `note`. A verb and URI with no match returns `No route found for GET orders.` and an error.

## Analysis

### `impact-of-change`

Reports what removing or renaming a class touches.

| Input | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `fqcn` | string | yes | none | An event, listener or job |
| `kind` | string | no | `remove` | `remove` or `rename`. Changes the wording of `notes` only |

For a listener, the response says which events it handles and which would be left with no handler:

```json
{
  "fqcn": "App\\Listeners\\ArchiveReceipt",
  "kind": "remove",
  "entity": "listener",
  "handles": ["App\\Events\\ReceiptSent"],
  "would_orphan_events": [],
  "dispatches": [],
  "notes": [
    "No handled event would be left without a handler by this change.",
    "Downstream dispatches from this class are listed; their own chains are not expanded here."
  ]
}
```

For an event (`"entity": "event"`), the response has `dispatchers`, `handlers`, and a `downstream` chain instead. A class Loom doesn't know returns `"entity": "unknown"` and a note. An unrecognized `kind` returns `Unknown kind [explode]; expected one of: remove, rename.`

### `find-orphans`

Takes no input. Returns events that nothing fires and nothing handles, and listeners that handle no event.

```json
{
  "orphan_events": [{ "fqcn": "App\\Events\\Lonely", "kind": "class", "file": "app/Events/Lonely.php", "line": 10 }],
  "idle_listeners": [{ "fqcn": "App\\Listeners\\Idle", "file": "app/Listeners/Idle.php", "line": 5 }]
}
```

An event that's fired only from a dispatch Loom couldn't resolve can look orphaned. Cross-check with `find-unresolved-dispatches`.

### `find-unresolved-dispatches`

Takes no input. Returns every dispatch Loom couldn't trace to a class.

```json
{
  "count": 1,
  "unresolved_dispatches": [
    { "file": "app/A.php", "line": 10, "expression": "dispatch($job)", "reason": "dynamic_class_name" }
  ]
}
```

The `reason` values and their fixes are in [Why was my code missed](../guides/why-was-my-code-missed.md#a-dispatch-shows-up-as-unresolved).
