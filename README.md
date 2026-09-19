<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-wide-dark.svg">
    <img src="art/logo-wide.svg" alt="Laravel Loom — Architecture as data" width="320">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/lucasp1337/laravel-loom/actions/workflows/run-tests.yml"><img src="https://github.com/lucasp1337/laravel-loom/actions/workflows/run-tests.yml/badge.svg?branch=main" alt="Tests"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
</p>

# Laravel Loom

Loom is a static analyzer that maps a Laravel app's events, listeners, jobs, mailables, notifications, schedules and routes into a JSON index, a browser UI and an MCP server.

Laravel wires an event up in several places at once: dispatched in a controller, handled by a listener that was auto-discovered from a type hint, and observed by a closure in a provider. Loom reads your source and answers "what happens when `OrderPlaced` fires?" in one lookup, with a file and line for every hop.

```bash
composer require lucasp1337/laravel-loom --dev
php artisan loom:scan
php artisan loom:show OrderPlaced
```

```json
{
  "events": [
    {
      "id": "App\\Events\\OrderPlaced",
      "file": "app/Events/OrderPlaced.php",
      "line": 5,
      "dispatched_from": [
        { "file": "app/Http/Controllers/OrderController.php", "line": 11, "method": "App\\Http\\Controllers\\OrderController::store" }
      ],
      "handled_by": [
        { "listener": "App\\Listeners\\SendOrderConfirmation", "method": "handle" }
      ]
    }
  ]
}
```

That is an excerpt: the scan wrote `storage/loom/index.json`, and `loom:show` printed it filtered to `OrderPlaced`. Open `/loom` in your local app to browse the same data. [Getting started](docs/getting-started.md) walks through it step by step.

## What you can do with it

- **See the wiring.** Every primitive, forward and backward, in [one index](docs/concepts/the-index.md). What gets picked up is in [what Loom sees](docs/concepts/what-loom-sees.md).
- **Browse it.** A read-only UI at `/loom`: [browse the UI](docs/guides/browse-the-ui.md).
- **Ask an agent.** `php artisan loom:mcp` serves the index to an MCP client: [ask an agent](docs/guides/ask-an-agent.md).
- **Gate your CI.** `loom:check` fails pull requests that add dead events or dispatch cycles, and `loom:diff` shows how the architecture changed between branches: [gate your CI](docs/guides/gate-your-ci.md).
- **Read it from code.** Load the index into typed PHP objects: [PHP API](docs/reference/php-api.md).

## What it is not

- Not a runtime tracer. Loom never boots your app or watches a request, so it reports what your source says, not what a given request did.
- Not complete for dynamic code. A dispatch like `event($class)` can't be resolved from source. Loom lists it under `unresolved_dispatches` with a file and line instead of guessing.
- Not a style linter. It has no opinion on your code beyond the [check rules](docs/reference/check-rules-and-formats.md) you turn on.

> [!NOTE]
> Install with `--dev`. The UI, the MCP server and the `viewLoom` gate then disappear from `composer install --no-dev` production builds.

## Requirements

- PHP 8.3 or newer
- Laravel 11, 12 or 13

`livewire/livewire` and `laravel/mcp` (^1.0) are required dependencies; Composer installs them with Loom (see [upgrading](docs/upgrading.md)).

## Documentation

Start at [getting started](docs/getting-started.md), or see the [command reference](docs/reference/commands.md) and the [JSON schema](docs/reference/schema.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
