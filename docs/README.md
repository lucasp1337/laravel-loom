# Laravel Atlas — Documentation

Documentation for contributors. For installation and the user-facing introduction, see the [root README](../README.md).

## Where to start

- **[Architecture](architecture.md)** — how Atlas is structured: the scanner contract, the cross-link pass, and the data flow from source code to JSON.
- **[Schema](schema.md)** — the shape of `storage/atlas/index.json`. Reference companion to `schema/atlas-index.schema.json`.
- **[Contributing](contributing.md)** — how to run the toolchain (PHPStan, Pint, Pest), how to add a scanner, how to add a fixture.

## Per-scanner behavior

Each scanner has a dedicated page documenting what it detects, what it emits, expected behavior on edge cases, and known limitations. When triaging an issue, check the relevant page to see whether the reported case is documented:

- **[Events](scanners/events.md)** — event class discovery (`app/Events/` walk + dispatch-site seeding)
- **[Listeners](scanners/listeners.md)** — `$listen` arrays, auto-discovery, `Event::listen()` calls
- **[Observers](scanners/observers.md)** — `Model::observe()`, `#[ObservedBy]`, `eloquent.*` listener strings; produces both `observers[]` and `model_events[]`
- **[Dispatches](scanners/dispatches.md)** — shallow dispatch-site scanning inside handlers; produces `unresolved_dispatches[]`, feeds the cross-link pass

## Reading order for new contributors

1. Root [README](../README.md) — what Atlas does and how to install it
2. [architecture.md](architecture.md) — the pipeline
3. [contributing.md](contributing.md) — how to develop locally
4. Pick a scanner doc that matches the area you're touching
