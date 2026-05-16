# Laravel Atlas

Static architectural inspector for Laravel applications. Scans source code and emits a structured JSON index of the application's event-driven architecture — events, listeners, observers, and the dispatch relationships between them.

No runtime tracing. No UI. No MCP. Just a discovery layer that produces a machine-readable index.

> *The architectural memory of your Laravel app — for humans, CI, and AI agents.*

## Status

**Pre-release.** All four scanners (events, listeners, observers, dispatches) and the cross-link pass are in place. The package is functional end-to-end. APIs may still shift before a tagged 0.1.0 release.

## Installation

```bash
composer require lucasp1337/laravel-atlas --dev
```

## Usage

```bash
php artisan atlas:scan          # writes storage/atlas/index.json
php artisan atlas:show          # prints the index
php artisan atlas:show OrderPlaced   # filters by FQCN substring
```

The output file lives at `storage/atlas/index.json` and is gitignored by default.

## Sample output

A representative `storage/atlas/index.json` with all four scanners active:

```json
{
  "atlas_version": "0.1.0",
  "scanned_at": "2026-05-16T14:22:00Z",
  "laravel_version": "12.x",
  "stats": {
    "events": 1,
    "listeners": 1,
    "observers": 1,
    "unresolved_dispatches": 1
  },
  "events": [
    {
      "id": "App\\Events\\OrderPlaced",
      "fqcn": "App\\Events\\OrderPlaced",
      "kind": "class",
      "file": "app/Events/OrderPlaced.php",
      "line": 12,
      "dispatched_from": [
        { "file": "app/Services/Checkout.php", "line": 87, "method": "App\\Services\\Checkout::finalize" }
      ],
      "handled_by": ["App\\Listeners\\SendOrderConfirmation"]
    }
  ],
  "model_events": [
    {
      "id": "eloquent.created: App\\Models\\User",
      "kind": "model_event",
      "model": "App\\Models\\User",
      "event": "created",
      "handled_by": ["App\\Observers\\UserObserver::created"]
    }
  ],
  "listeners": [
    {
      "fqcn": "App\\Listeners\\SendOrderConfirmation",
      "file": "app/Listeners/SendOrderConfirmation.php",
      "line": 14,
      "handles": ["App\\Events\\OrderPlaced"],
      "registration": "listen_array",
      "queued": true,
      "dispatches": [
        {
          "target": "App\\Events\\OrderConfirmationSent",
          "kind": "event",
          "confidence": "high",
          "file": "app/Listeners/SendOrderConfirmation.php",
          "line": 31
        }
      ]
    }
  ],
  "observers": [
    {
      "fqcn": "App\\Observers\\UserObserver",
      "file": "app/Observers/UserObserver.php",
      "line": 9,
      "observes": "App\\Models\\User",
      "registration": "attribute",
      "hooks": ["created", "deleted", "updated"],
      "dispatches": []
    }
  ],
  "unresolved_dispatches": [
    {
      "file": "app/Services/Notifier.php",
      "line": 42,
      "expression": "event($eventClass)",
      "reason": "dynamic_class_name"
    }
  ]
}
```

## Requirements

- PHP **8.3+**
- Laravel **11.0+**

## Local development

Installing the package only needs PHP 8.3+, but running the test suite locally needs `ext-mbstring`, `ext-xml`, `ext-dom`, and `ext-xmlwriter`. A `Dockerfile` plus a `Justfile` are provided so contributors without those extensions on their host PHP can run the full toolchain:

```bash
just build    # build the Docker dev image
just install  # composer install inside the container
just check    # PHPStan + Pint --test + Pest
```

See `docs/contributing.md` for the full list of recipes.

## What it detects

- **Events** — classes in `app/Events`, plus any class dispatched via `event()`, `Event::dispatch()`, or `X::dispatch()`
- **Listeners** — `$listen` array on `EventServiceProvider`, Laravel 11+ auto-discovery via typed `handle()`, and `Event::listen()` calls anywhere under `app/`
- **Observers** — `Model::observe()` calls (including `static::observe(...)` in `booted()`), the `#[ObservedBy]` attribute, plus Eloquent model events synthesized from observer hooks and `Event::listen('eloquent.*', …)`
- **Shallow dispatch scan** — one level of `event()` / `dispatch()` / `Bus::dispatch()` / `X::dispatch()` calls inside each handler. Unresolvable dynamic dispatches surface in `unresolved_dispatches[]` rather than being silently dropped.

## What it does not detect

Container bindings, the scheduler, broadcast channels, notifications, mailables, job class internals (beyond dispatch sites), subscribers, runtime tracing, an MCP server, a Blade UI, Markdown export, diff/CI integration, multi-framework support, Laravel < 11 support.

See [docs/scanners/](docs/scanners/) for per-scanner edge cases and known limitations.

## Documentation

- [Architecture](docs/architecture.md) — pipeline, scanner contract, cross-link pass
- [Schema](docs/schema.md) — JSON schema reference
- [Scanners](docs/scanners/) — per-scanner behavior, edge cases, known limitations
- [Contributing](docs/contributing.md) — toolchain, Docker workflow, how to add a scanner

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
