---
name: loom
description: Answer questions about this Laravel app's events, listeners, jobs, observers, routes and scheduled tasks using the Loom index. Use for impact analysis before changing or removing one of them, finding orphaned events and listeners, and tracing an HTTP route to the events it sets off.
---

# Loom

Loom exposes a read-only index of the app's event-driven wiring through MCP tools. Run `php artisan loom:scan` first if the code changed since the last scan.

## Impact analysis

Use before removing or renaming an event, listener or job.

1. Call `impact-of-change` with `fqcn` and `change` (`remove` or `rename`).
2. Read `would_orphan_events` (listeners) or `dispatchers` and `handlers` (events).
3. For an event, call `events-following` with `event_fqcn` to see what else it sets off.
4. If `kind` is `unknown`, the class is not in the index. Say so; do not infer.

## Find orphans

1. Call `find-orphans` for `orphan_events` and `idle_listeners`.
2. Call `find-unresolved-dispatches`. An event fired only from an unresolved dispatch can look orphaned.
3. Open each remaining candidate at its `file` and `line` before proposing a removal.

## Trace a route to its events

1. Call `route-to-events` with `method`, `uri` and optionally `depth` (default 3, max 6).
2. Read `chain.dispatches` for direct dispatches and `chain.chains` for downstream handlers.
3. If `truncated` is `true`, call again with a larger `depth`. If `chain` is `null`, the route is a closure.
4. Use `get-entity` with `kind` and `fqcn` for the full record of any class in the chain.
