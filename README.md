<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-wide-dark.svg">
    <img src="art/logo-wide.svg" alt="Laravel Loom — Architecture as data" width="320">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/lucasp1337/laravel-loom/actions/workflows/run-tests.yml"><img src="https://github.com/lucasp1337/laravel-loom/actions/workflows/run-tests.yml/badge.svg?branch=main" alt="Tests"></a>
  <a href="https://github.com/lucasp1337/laravel-loom/actions/workflows/phpstan.yml"><img src="https://github.com/lucasp1337/laravel-loom/actions/workflows/phpstan.yml/badge.svg?branch=main" alt="PHPStan"></a>
  <a href="https://codecov.io/gh/lucasp1337/laravel-loom"><img src="https://codecov.io/gh/lucasp1337/laravel-loom/graph/badge.svg" alt="Coverage"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
</p>

# Laravel Loom

**See your Laravel application's event-driven architecture at a glance.**

Loom is a static analyzer that reads your application's source code and produces a structured JSON index of every event, listener, observer, and dispatch site — with file paths and line numbers, ready for humans, CI pipelines, and AI agents to consume.

```bash
php artisan loom:scan
# storage/loom/index.json
```

No runtime tracing. No service-container introspection. No booting your app. Loom walks PHP source, resolves names with `nikic/php-parser`, and writes a deterministic JSON file. It works on a freshly cloned repo without `vendor/`.

## Why?

Event-driven Laravel apps drift fast. Listeners get added in providers, observers attached in `booted()`, dispatches scattered across services. `php artisan event:list` shows you what Laravel happened to register at boot — not what's actually in your source. Observers don't appear at all. Dispatch sites are invisible.

Loom answers the questions that command can't:

- *Where is `OrderPlaced` dispatched from?* → `events[].dispatched_from`
- *Which listeners handle it?* → `events[].handled_by`
- *What does `SendOrderConfirmation::handle()` dispatch internally?* → `listeners[].dispatches`
- *Which observers run on `App\Models\User`?* → `observers[]` + synthesized `model_events[]`
- *Are there any dynamic `event($variable)` calls that static analysis can't resolve?* → `unresolved_dispatches[]`

The output is a single JSON file. Diff it in CI to catch architectural drift. Feed it to your coding agent to give it instant context on your event flow. Render it as a graph. Search it. Whatever you want — it's just JSON.

## Installation

```bash
composer require lucasp1337/laravel-loom --dev
```

PHP 8.3+ and Laravel 11, 12, or 13.

## Usage

```bash
php artisan loom:scan          # writes storage/loom/index.json
php artisan loom:show          # prints the index
php artisan loom:show OrderPlaced   # filters by FQCN substring
```

The output file lives at `storage/loom/index.json` and is gitignored by default — consume it from CI, commit it deliberately, or ignore it. Up to you.

## What gets discovered

| Primitive | Discovery paths |
|---|---|
| **Events** | `app/Events/**` filesystem walk, plus any class dispatched via `event()`, `Event::dispatch()`, or `X::dispatch()` |
| **Listeners** | The `$listen` array on `EventServiceProvider`, Laravel 11+ auto-discovery via typed `handle()` parameters, `Event::listen()` calls anywhere under `app/` (DDD-style providers in `app/Domain/.../Providers/` are supported), and subscribers registered via `$subscribe` or `Event::subscribe()` |
| **Closure listeners** | Closure and arrow-function values inside `$listen` arrays, as the second argument to `Event::listen()`, and inside subscriber `subscribe()` return-arrays. Emitted as a separate `closure_listeners[]` section. |
| **Observers** | `Model::observe()` calls (including `static::observe(...)` inside `booted()`), the `#[ObservedBy]` attribute, plus Eloquent model events synthesized from observer hooks and `Event::listen('eloquent.*', …)` |
| **Dispatches** | One-level scan of every method body for `event()`, `Event::dispatch()`, `dispatch()`, `Bus::dispatch()`, and `X::dispatch()` calls. Cross-links them to events, listeners, and observers. |

Loom favors *surfacing gaps* over hiding them. Dynamic dispatches like `event($variable)` or `event("App\\Events\\{$name}")` don't disappear — they land in `unresolved_dispatches[]` with the reason (`dynamic_class_name`, `string_concatenation`, `container_resolution`, `conditional_dispatch`) and a file:line pointer.

## Sample output

A representative scan against a small Laravel 13 app:

```json
{
  "loom_version": "0.2.0",
  "scanned_at": "2026-05-16T19:25:54Z",
  "laravel_version": "13.7",
  "stats": {
    "events": 3,
    "listeners": 3,
    "observers": 3,
    "unresolved_dispatches": 0
  },
  "events": [
    {
      "id": "App\\Events\\OrderPlaced",
      "fqcn": "App\\Events\\OrderPlaced",
      "kind": "class",
      "file": "app/Events/OrderPlaced.php",
      "line": 11,
      "dispatched_from": [
        { "file": "app/Services/Checkout.php", "line": 87, "method": "App\\Services\\Checkout::finalize" }
      ],
      "handled_by": [
        { "listener": "App\\Listeners\\SendOrderConfirmation", "method": "handle" }
      ]
    }
  ],
  "model_events": [
    {
      "id": "eloquent.creating: App\\Models\\User",
      "kind": "model_event",
      "model": "App\\Models\\User",
      "event": "creating",
      "handled_by": ["App\\Observers\\UserObserver::creating"]
    }
  ],
  "listeners": [
    {
      "fqcn": "App\\Listeners\\SendOrderConfirmation",
      "file": "app/Listeners/SendOrderConfirmation.php",
      "line": 14,
      "handles": [
        { "event": "App\\Events\\OrderPlaced", "method": "handle" }
      ],
      "registration": "auto_discovered",
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

Schema is locked at `schema/loom-index.schema.json` — every scan is validated against it before being written.

## Performance

Loom is fast because it does one thing. On a fresh `laravel new` app, the scan finishes in well under a second. A medium-sized real-world app (~200 PHP files in `app/`) scans in **~200ms**.

## What it does not do

Loom is deliberately narrow. Not in scope:

- Container bindings, the scheduler, broadcast channels, notifications, mailables
- Analysis of job class internals (Loom records dispatch sites; what happens inside the job is your queue worker's concern)
- Runtime tracing of any kind
- An MCP server, Blade UI, Markdown export, diff / CI integration
- Multi-framework support, Laravel < 11

For per-scanner edge cases and known limitations, see [docs/scanners/](docs/scanners/).

## Requirements

- PHP **8.3+**
- Laravel **11, 12, or 13**

## Local development

Installing the package only needs PHP 8.3+, but running the test suite needs `ext-mbstring`, `ext-xml`, `ext-dom`, and `ext-xmlwriter`. A `Dockerfile` plus a `Justfile` are provided so contributors without those extensions on their host PHP can run the full toolchain:

```bash
just build    # build the Docker dev image (once)
just install  # composer install
just check    # PHPStan + Pint --test + Pest
just coverage # Pest with per-file coverage
```

See [docs/contributing.md](docs/contributing.md) for the full list of recipes (including `just scan <path>` to run Loom against any Laravel app on disk).

## Documentation

- [Architecture](docs/architecture.md) — pipeline, scanner contract, cross-link pass
- [Schema](docs/schema.md) — JSON schema reference
- [Scanners](docs/scanners/) — per-scanner behavior, edge cases, known limitations
- [Contributing](docs/contributing.md) — toolchain, Docker workflow, how to add a scanner

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
