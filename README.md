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

*Architecture as data.*

Static analyzer for Laravel's event-driven primitives. Scans your source and writes a JSON file listing the events, listeners, observers, and dispatch sites it finds — with file paths and line numbers. Unresolved dispatch calls inside method bodies (`event($variable)`, container lookups) are surfaced with a reason code rather than dropped silently; top-level scripts and closure bodies are skipped entirely. Per-scanner edge cases live in [docs/scanners/](docs/scanners/).

```bash
php artisan loom:scan
# storage/loom/index.json
```

No app boot, no runtime tracing, no `vendor/` required. Loom reads PHP source with `nikic/php-parser` and emits a deterministic JSON file.

## Why

Event-driven Laravel apps drift fast. Listeners get added in providers, observers attached in `booted()`, dispatches scattered across services. `php artisan event:list` shows you what Laravel happened to register at boot — not what's actually in your source. Observers don't appear at all. Dispatch sites are invisible. Subscribers vary by registration form.

Loom answers the questions that command can't:

- *Where is `OrderPlaced` dispatched from?* → `events[].dispatched_from`
- *Which listeners handle it, and via which methods?* → `events[].handled_by` (class-based) + `closure_listeners[]` filtered by `event` (closures, which don't have an FQCN to cross-link)
- *What does `SendOrderConfirmation::handle()` dispatch?* → `listeners[].dispatches`
- *Which observers run on `App\Models\User`?* → `observers[]` + `model_events[]`
- *Any dynamic dispatches Loom couldn't pin down?* → `unresolved_dispatches[]` with a reason and a file:line

Per-scanner edge cases and known limitations live in [docs/scanners/](docs/scanners/).

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

The output lives at `storage/loom/index.json`. Add it to your `.gitignore` if you don't want to commit it.

## What gets discovered

- **Events** — `app/Events/**` walk, plus any class statically dispatched via `event(new Foo)` / `Event::dispatch(new Foo)` (regardless of where the class lives). The ambiguous Dispatchable form `X::dispatch()` only counts as an event when the target resolves under `app/Events/`.
- **Listeners** — Laravel's auto-discovery, `$listen` arrays on `EventServiceProvider`, `Event::listen()` calls anywhere under `app/`, and subscribers via `$subscribe` / `Event::subscribe()`.
- **Closure listeners** — closures and arrow functions wherever a listener can be (`$listen`, `Event::listen()`, subscriber bodies). Emitted as a separate `closure_listeners[]` section.
- **Observers** — `Model::observe()` calls, the `#[ObservedBy]` attribute, plus model events synthesized from observer hooks and `Event::listen('eloquent.*', …)`.
- **Jobs** — classes under `app/Jobs/` (recursive), plus any class dispatched via `dispatch()`, `Bus::dispatch()`, or the Dispatchable form `X::dispatch()` (located via PSR-4, so jobs in DDD layouts get picked up). Records `queued` and `queue_config` (connection, queue, delay, tries, timeout, backoff) when declared as class properties.
- **Schedule** — entries declared via the `schedule(Schedule $schedule)` method on `app/Console/Kernel.php`, the `->withSchedule(...)` callback on `Application::configure(...)` in `bootstrap/app.php`, and `Schedule::call/command/job/exec(...)` chains anywhere under `app/`. Records `kind`, resolved `target`, a five-field `cron` expression normalized from Laravel's frequency helpers, `timezone`, the `without_overlapping` / `on_one_server` / `run_in_background` flags, and a sorted list of opaque `constraints[]` labels (`weekdays`, `between(8:00,17:00)`, `when(closure)`, `environments(production)`, …).
- **Dispatches** — every method body scanned for `event()`, `Event::dispatch()`, `dispatch()`, `Bus::dispatch()`, and `X::dispatch()`. Cross-linked back to listeners and observers by handler method.

## Sample output

<details>
<summary>Click to expand a representative scan against a small Laravel 13 app</summary>

```json
{
  "loom_version": "0.2.0",
  "scanned_at": "2026-05-16T19:25:54Z",
  "laravel_version": "13.7",
  "stats": {
    "events": 1,
    "listeners": 1,
    "observers": 1,
    "jobs": 1,
    "scheduled": 1,
    "unresolved_dispatches": 1,
    "closure_listeners": 1
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
  "jobs": [
    {
      "fqcn": "App\\Jobs\\ProcessOrder",
      "file": "app/Jobs/ProcessOrder.php",
      "line": 14,
      "queued": true,
      "queue_config": {
        "connection": "redis",
        "queue": "high",
        "delay": null,
        "tries": 3,
        "timeout": 60,
        "backoff": null
      },
      "dispatched_from": [
        { "file": "app/Services/Checkout.php", "line": 91, "method": "App\\Services\\Checkout::finalize" }
      ],
      "dispatches": []
    }
  ],
  "scheduled": [
    {
      "kind": "command",
      "target": "mail:send {--queue=default}",
      "cron": "0 13 * * *",
      "timezone": "America/Chicago",
      "without_overlapping": true,
      "on_one_server": false,
      "run_in_background": false,
      "constraints": ["weekdays"],
      "file": "app/Console/Kernel.php",
      "line": 28
    }
  ],
  "unresolved_dispatches": [
    {
      "file": "app/Services/Notifier.php",
      "line": 42,
      "expression": "event($eventClass)",
      "reason": "dynamic_class_name"
    }
  ],
  "closure_listeners": [
    {
      "event": "App\\Events\\OrderPlaced",
      "file": "app/Providers/EventServiceProvider.php",
      "line": 38,
      "registration": "event_listen_call",
      "queued": false,
      "dispatches": []
    }
  ]
}
```

</details>

The JSON shape is defined by `schema/loom-index.schema.json` and validated on every scan. The schema follows semver — but pre-1.0, breaking changes are tolerated and called out in the [CHANGELOG](CHANGELOG.md). From 1.0 onwards, breaking changes will require a major bump.

## Performance

On a fresh `laravel new` app, the scan finishes in well under a second. A medium-sized real-world app (~200 PHP files in `app/`) scans in around 200ms.

## What's planned

Tracked at the [v1.0 milestone](https://github.com/lucasp1337/laravel-loom/milestone/1). Highlights:

- More sections: [mailables and notifications](https://github.com/lucasp1337/laravel-loom/issues/6), [routes](https://github.com/lucasp1337/laravel-loom/issues/8).
- A [browser UI](https://github.com/lucasp1337/laravel-loom/issues/19) for clicking through the index — events, listeners, dispatch chains.
- An [MCP server](https://github.com/lucasp1337/laravel-loom/issues/20) so AI coding assistants can query the index instead of grepping.
- [`loom:diff`](https://github.com/lucasp1337/laravel-loom/issues/9) and [`loom:check`](https://github.com/lucasp1337/laravel-loom/issues/10) for CI.
- A few correctness fixes — [container-form registrations](https://github.com/lucasp1337/laravel-loom/issues/15), [indirect `ShouldQueue`](https://github.com/lucasp1337/laravel-loom/issues/14), [closure dispatch attribution](https://github.com/lucasp1337/laravel-loom/issues/16).

Out of scope: runtime tracing, IDE plugins, complexity/quality metrics, and data-model / access-control primitives (models, migrations, validators, policies). Loom's domain is *control flow* — what dispatches what, what handles what, what runs when — which includes routes and schedules even though they're not strictly `Event::dispatch()`-shaped.

For per-scanner edge cases and known limitations today, see [docs/scanners/](docs/scanners/).

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
