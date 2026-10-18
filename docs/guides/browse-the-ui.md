# Browse the UI

Open `/loom` in your local app and follow `OrderPlaced` through every listener and job it triggers.

```bash
composer require livewire/livewire --dev
php artisan loom:scan
php artisan serve
```

Open `http://localhost:8000/loom`. The dashboard counts each primitive, lists orphans (events nobody handles, listeners with no event, unresolved dispatches) and ranks events by fan-out. The UI reads the index and never scans, so scan again after you change code.

## Follow an event

Click **Events**, then `OrderPlaced`. The page lists where the event is dispatched, the listeners that handle it and what they dispatch next. Click **View chain** to see the trail as a graph, one column per hop:

- Click a node to read its facts and open its page.
- Double-click a node to collapse or expand it.
- The depth selector sets how many hops are drawn. Depth and selection live in the address bar, so the link reopens the same view.

Jobs, mailables and notifications are leaves, and observers are not drawn. Which calls count as a listener's dispatches is shown in [Dispatches](../reference/detection/dispatches.md).

| Message | Meaning |
| --- | --- |
| `Depth limit reached. Raise the depth to expand the remaining events.` | Handlers exist past the edge; pick a higher depth. |
| `No handlers registered for this event.` | Nothing in the index handles it; see [Why was my code missed?](why-was-my-code-missed.md) |
| `↺ already shown` | The event was drawn on another branch. It isn't always a loop: the `cyclic-dispatch` rule of [`loom:check`](../reference/check-rules-and-formats.md#rules) reports real cycles. |

## Find things

Each sidebar section is a table you can filter and sort; the address bar keeps the view, so `/loom/listeners?q=Order&sort=dispatch_count&dir=desc` is a saved search. Press `Ctrl K` (`Cmd K`) or `/` to search every section by class, route or file.

| Keys | Action |
| --- | --- |
| `Ctrl K` / `Cmd K`, or `/` | Open the palette |
| Esc | Close the palette or drawer; deselect a node |
| `g` then `d` | Dashboard |
| `g` then `e` | Events |
| `g` then `l` | Listeners |
| `g` then `o` | Observers |
| `g` then `j` | Jobs |
| `g` then `c` | Closure listeners |
| `g` then `m` | Mailables |
| `g` then `n` | Notifications |
| `g` then `r` | Routes |

## When the page isn't what you expect

| You see | Do |
| --- | --- |
| 503 `No index found` or `Index could not be loaded` | Run `php artisan loom:scan` |
| 403 | The `viewLoom` gate refused you; see below |
| 404 on `/loom` | Add the environment to `ui.environments`, check `ui.enabled`, and run `php artisan route:clear` if routes are cached |
| `Index is 6 days older than your last commit to app/` | Run `php artisan loom:scan`. The banner compares commits, so it never shows for uncommitted changes or outside a git checkout. |

## Open it on staging

List the environment in `ui.environments`, then define the `viewLoom` gate; yours replaces the default, which allows only `local`:

<!-- illustrative -->

```php
Gate::define('viewLoom', fn (?User $user): bool => in_array($user?->email, ['dev@shop.test'], true));
```

The gate runs on every page load and every click. `production` is ignored unless `ui.allow_in_production` is `true`. The path, domain and kill switch are in [UI configuration](../reference/ui-config.md#browser-ui).
