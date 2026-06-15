# EventScanner

Discovers event classes and emits the `events[]` section of the index.

## What it detects

EventScanner uses two discovery paths and merges them by FQCN:

1. **Filesystem walk of `app/Events/`.** Every top-level `class` declaration with a non-null `namespacedName` becomes an event entry. Abstract classes are included. Interfaces and traits are skipped. Anonymous classes are skipped (they have no FQCN).

2. **Dispatch-site seeding across `app/`.** EventScanner walks every PHP file under `app/` and records statically resolvable targets from:
   - `event(new SomeEvent(...))` and `event(SomeEvent::class)` (`form: helper`). The first argument is extracted even with trailing args, so `event(new SomeEvent($payload), $extraArgs)` seeds `SomeEvent`. Plain-old-PHP-object events (no `Dispatchable` trait) are discovered this way.
   - `broadcast(new SomeEvent(...))` and `broadcast(SomeEvent::class)` (`form: helper`). The bare broadcast form seeds discovery the same way `event(...)` does, so broadcast-only events reach `events[]`. (The conditional `broadcast_if`/`broadcast_unless` forms are emitted as dispatch sites by DispatchScanner but are not used for discovery seeding here.)
   - `Event::dispatch(new SomeEvent(...))` and `Event::dispatch(SomeEvent::class)` (`form: facade`)
   - `SomeEvent::dispatch(...)`, `SomeEvent::dispatchIf($cond, ...)`, and `SomeEvent::dispatchUnless($cond, ...)` (`form: dispatchable`). The conditional forms resolve to the same target as `dispatch(...)`; the leading condition argument is ignored for resolution.

   When a seeded target's FQCN isn't already known from the filesystem walk, EventScanner locates the class file via a PSR-4 guess (mapping leading `App\` to `app/`). The class must exist on disk and contain the declared FQCN; otherwise the candidate is dropped.

   The `dispatchable` form is subject to an extra filter: candidates are only accepted if their resolved file sits under `app/Events/`. Without this filter, every job class using the `Dispatchable` trait would land in `events[]`. The `helper` and `facade` forms have no such filter — they are unambiguous event dispatches per Laravel's API.

## Output

One entry per discovered event FQCN, conforming to `$defs/event`:

```json
{
  "id": "App\\Events\\OrderPlaced",
  "fqcn": "App\\Events\\OrderPlaced",
  "kind": "class",
  "file": "app/Events/OrderPlaced.php",
  "line": 12,
  "dispatched_from": [],
  "handled_by": []
}
```

`dispatched_from` and `handled_by` are emitted as empty arrays. They are populated by the cross-link pass in `IndexBuilder` using DispatchScanner and ListenerScanner output. EventScanner never writes to those fields directly.

Entries are sorted by `fqcn` ascending. `id` always equals `fqcn` (the schema reserves `id` for future kinds; `kind` is always `"class"`).

## Expected behavior

- **Event class outside `app/Events/`.** Picked up when dispatched via the helper or facade form. Filesystem walk's `file`/`line` wins over the dispatch-site discovery (the class declaration is more authoritative than the call site).
- **Multiple classes in one file.** Each top-level `Stmt\Class_` produces its own entry. This is a PSR-4 violation but legal PHP, and the scanner handles it.
- **Abstract base event classes.** Included. They have a file and line and may be referenced by subclasses.
- **Trait usage on event classes.** No special handling. The class itself is recorded; the trait is irrelevant.
- **Dispatch site uses `\Fully\Qualified\Name`.** Resolved correctly via NameResolver — fully qualified names need no `use` statement.
- **Dispatch site inside a closure.** The dispatch-site visitor reads on `leaveNode`, so closures are walked. Their resolved targets do contribute to event discovery (they're real references to event classes), but DispatchScanner skips emitting `dispatched_from` for sites inside closures. See [dispatches.md](dispatches.md).
- **Same event discovered via multiple paths.** Deduped by FQCN. Filesystem walk wins for `file`/`line`.
- **Parse errors in a file.** `AstWalker` swallows them. The scanner sees no hits from that file.

## Known limitations

- **Configurable events directory.** Hardcoded to `app/Events/`. Projects that store events elsewhere are only discovered via dispatch-site seeding. Discovery from the dispatch site populates the entry correctly (file/line of the class declaration), but if the project has events in (say) `app/Domain/Events/` that are never dispatched anywhere in `app/`, they won't be found.
- **`X::dispatch(...)` for events outside `app/Events/`.** Dropped by the Dispatchable trim. The trim is necessary to avoid promoting every job to an event; it has the downside of missing rare-but-legitimate events in non-standard locations. Workaround: use `event(new ...)` or `Event::dispatch(...)` for those classes.
- **Dynamic dispatch targets.** `event($variable)` doesn't contribute to event discovery. This is by design — the static analyzer can't resolve the variable. The dispatch site surfaces in `unresolved_dispatches[]` via DispatchScanner.
- **Closure / arrow-function event classes.** Anonymous classes have no FQCN and are skipped.
- **Vendor / storage directories.** Never walked. Loom only scans `app/`.
- **`Event::listen('eloquent.*', …)`** — these are model events, not class events. They appear in `model_events[]`, not `events[]`. See [observers.md](observers.md).
- **`ShouldBroadcast` marker interface.** Not surfaced as a flag. The bare `broadcast(new Event)` / `broadcast(Event::class)` form IS now recognised and seeds discovery (see "What it detects"), so a broadcast-only event reaches `events[]`. The conditional `broadcast_if`/`broadcast_unless` forms are not used for discovery seeding, so an event dispatched *only* through those, outside `app/Events/`, is not discovered.

## When something looks wrong

Triage checklist for missing events:

1. Is the event class under `app/Events/`? Yes → should be discovered via filesystem walk.
2. Is it dispatched via `event(...)`, `broadcast(...)`, or `Event::dispatch(...)`? Yes → should be discovered via dispatch-site seeding, with file/line from its declaration if the PSR-4 guess can find it.
3. Is it dispatched only via `SomeClass::dispatch(...)` and located outside `app/Events/`? That's the documented limitation above.
4. Is it referenced dynamically (`event($var)`)? Check `unresolved_dispatches[]` — the dispatch site should be there.
5. Does the file parse cleanly? Run `php -l path/to/file.php`. Parse errors are silently swallowed.

For unexpected entries (e.g. a job class showing up in `events[]`): check whether it was reached via dispatch-site seeding and lives under `app/Events/` (PSR-4 violation). The Dispatchable trim should prevent this for jobs in `app/Jobs/`.
