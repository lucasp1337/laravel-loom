# Getting started

By the end of this page you'll have an index of your app on disk, you'll know where an event goes when it fires, and you'll have seen the same data in the browser.

The examples use a checkout app with an `OrderPlaced` event. Swap in any event of yours.

You need PHP 8.3 or newer and a Laravel 11, 12 or 13 app.

## Scan your app

Install Loom as a dev dependency, then scan.

```bash
composer require lucasp1337/laravel-loom --dev
php artisan loom:scan
```

```text
Loom index written to /var/www/shop/storage/loom/index.json
```

That file is the index: one JSON document with every event, listener, job, mailable, notification, schedule and route Loom found, each with a file and line. The scan reads your source and never boots your app, so it's safe to run anywhere.

## Follow an event

Ask for the index filtered to one event. The argument is a substring of the class name.

```bash
php artisan loom:show OrderPlaced
```

The `events` entry for `OrderPlaced` tells you both directions of the story:

```json
{
  "id": "App\\Events\\OrderPlaced",
  "fqcn": "App\\Events\\OrderPlaced",
  "kind": "class",
  "file": "app/Events/OrderPlaced.php",
  "line": 5,
  "dispatched_from": [
    { "file": "app/Http/Controllers/OrderController.php", "line": 11, "method": "App\\Http\\Controllers\\OrderController::store" }
  ],
  "handled_by": [
    { "listener": "App\\Listeners\\SendOrderConfirmation", "method": "handle" }
  ]
}
```

`dispatched_from` lists every place your code fires the event, and `handled_by` lists every listener that reacts. You didn't register `SendOrderConfirmation` anywhere: Loom found it because its `handle()` method is typed `OrderPlaced`, the same way Laravel's auto-discovery does.

!!! warning "The filter is partial"
    It applies to events, listeners, observers and model events only. Jobs, mailables, notifications, routes and schedules print in full, so searching for `SendReceipt` returns those sections unfiltered.

The output above is one entry. Follow `handled_by` to the listener's own entry to see what it dispatches next, and [the index](concepts/the-index.md) shows how to read that chain.

## Open the UI

Loom also mounts a read-only browser UI in your app. Visit `/loom` locally.

```text
http://localhost:8000/loom
```

You land on a dashboard with a count for each primitive, and every entry links to its detail page. The UI reads the file `loom:scan` wrote; it never scans itself, so scan again after you change code.

!!! warning "Local only by default"
    Only the `local` environment can open `/loom`. Anywhere else you get a 403 until you define a `viewLoom` gate. [Browse the UI](guides/browse-the-ui.md) covers the gate and the config.

## When it doesn't work

Run `loom:show` on a machine that hasn't scanned yet and you get:

```text
Loom index not found at /var/www/shop/storage/loom/index.json. Run `php artisan loom:scan` first.
```

Scan first. The command exits with a failure status, so a script notices too.

If a dispatch you expected is missing, it may have landed in the `unresolved_dispatches` section instead. Loom lists calls like `event($class)` there, with a file and line, because it can't tell which class a variable holds. [Why was my code missed?](guides/why-was-my-code-missed.md) walks through the common symptoms.

## Where to next

- Keep the file out of git with a `.gitignore` entry, or commit it if you want to [diff it between branches and gate on it](guides/gate-your-ci.md).
- [What Loom sees](concepts/what-loom-sees.md) lists every primitive and what's recorded for each.
- [Ask an agent](guides/ask-an-agent.md) points an AI assistant at the index instead of your source tree.
