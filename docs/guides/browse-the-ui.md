# Browse the UI

By the end of this page `/loom` is open in your browser and you have followed `OrderPlaced` through every listener and job it triggers. You have run `php artisan loom:scan`. The examples use a checkout app where `OrderPlaced` fires from `OrderController` and is handled by `SendOrderConfirmation` and `ReserveStock`.

The UI needs `livewire/livewire` (^3.8 or ^4.0), which Loom doesn't install: `composer require livewire/livewire --dev`. Without it nothing is mounted, and `loom:scan` prints that hint.

## See what happens when OrderPlaced fires

```bash
php artisan serve   # then open http://localhost:8000/loom
```

The dashboard shows a count per primitive, an orphans card and the events with the biggest fan-out. Open **Events**, then `OrderPlaced`: the page lists where it is dispatched, who handles it and what those handlers dispatch. **View chain** draws the trail as a graph, one column per hop: `OrderPlaced` at the left, its listeners next, then what they dispatch. Click a node for its facts in the side panel.

!!! warning "The UI never scans"
    It reads the file `loom:scan` wrote, so after a code change you see the old picture until you scan again.

## Read the chain view

The depth selector runs from 1 to 6 (default 3) and counts hops: depth 1 is the event and its handlers, depth 2 adds the events those dispatch and their handlers. Click selects a node; double-click collapses a node with children (`⊕`). Changing depth resets collapsed nodes. Depth and selection live in the address bar (`?depth=4`), so the link can be shared.

| Message | Meaning |
| --- | --- |
| `Depth limit reached. Raise the depth to expand the remaining events.` | An event at the edge has handlers you aren't seeing. |
| `No handlers registered for this event.` | Nothing in the index handles the root. See the [orphan list](#the-dashboard) or [why your code was missed](why-was-my-code-missed.md). |
| `↺ already shown` | The event was drawn earlier on another branch and stops here. It is not necessarily a loop; `loom:check`'s `cyclic-dispatch` rule reports true loops ([rules](../reference/check-rules-and-formats.md)). |

Jobs, mailables and notifications are leaves, observers don't appear as event handlers, and dispatches are attributed to the whole class, not the method.

## Find things in the lists

Every sidebar section is a table you can filter by class name, namespace or file path and sort by header; lists page at 50 rows, and the address bar keeps the view, so `/loom/listeners?q=Order&sort=dispatch_count&dir=desc` is a saved search. A row marked `orphan` is an event nobody handles or dispatches, or a listener that handles no event.

## Jump anywhere

`Ctrl K` (`Cmd K` on a Mac) or `/` opens the command palette. It searches events, listeners, observers, jobs, mailables, notifications, routes and closure listeners, exact and prefix matches first, 20 hits at most.

| Keys | Action |
| --- | --- |
| `Ctrl K` / `Cmd K`, or `/` | Open the palette |
| Up, Down, Enter | Move through results and open one |
| Esc | Close the palette or drawer; deselect a chain node |
| `g` then `d` | Dashboard |
| `g` then `e` | Events |
| `g` then `l` | Listeners |
| `g` then `o` | Observers |
| `g` then `j` | Jobs |
| `g` then `c` | Closure listeners |
| `g` then `m` | Mailables |
| `g` then `n` | Notifications |
| `g` then `r` | Routes |

Press the second key within about a second of `g`. The `g` shortcuts are ignored while typing in an input and only exist for sections the index contains.

## The dashboard

Counters link to each section. The Orphans card lists up to eight events with no handlers, listeners with no event and unresolved dispatches; the fan-out card ranks events by handler count and links to their chain view.

## When the page isn't what you expect

| You see | Why and what to do |
| --- | --- |
| 503 `No index found` | Run `php artisan loom:scan` and reload. |
| 503 `Index could not be loaded` | The file isn't a valid index; scan again. The message shows the parse error. |
| 403 | The `viewLoom` gate refused you. See [Open it on staging](#open-it-on-staging). |
| 404 on `/loom` | The environment isn't in `ui.environments`, or the UI is disabled. |
| 404 on a class | It isn't in the index; check the spelling or scan again. |
| `Index is 6 days older than your last commit to app/` | Run `php artisan loom:scan`. |

The stale banner compares the scan time with the newest git commit touching `app/`. It needs a git checkout with `git` on the path, is cached for 30 seconds, and ignores uncommitted changes. Silence is not proof the index is fresh.

## Open it on staging

By default the UI exists only in the `local` environment; elsewhere nothing is registered and `/loom` is a 404, because the index lists file paths and internal class names. To open it on staging, add the environment and define a `viewLoom` gate, which replaces the default:

```php
// config/loom.php
'ui' => ['environments' => ['local', 'staging']],

// AppServiceProvider::boot()
Gate::define('viewLoom', fn (?User $user): bool => app()->environment('staging')
    && in_array($user?->email, ['dev@shop.test', 'lead@shop.test'], true));
```

A guest passes `null` and gets 403. A 403 means the UI is mounted and the gate refused you; a 404 means the environment isn't listed. The gate runs on the first load and on every interaction, so revoking access applies on the next click; only the stylesheet and script under `/loom/assets` are served outside it.

!!! warning "The gate is the only lock"
    `ui.middleware` runs before the gate, and the gate check always runs. `production` in `ui.environments` is ignored unless `ui.allow_in_production` is `true`. Leave it off.

## Change the URL, turn it off

```dotenv
LOOM_PATH=architecture
LOOM_DOMAIN=internal.shop.test
LOOM_UI_ENABLED=false
```

These serve the UI at `/architecture`, restrict it to one domain, and remove it entirely (no routes, gate or pages). Everything else is in [UI configuration](../reference/ui-config.md); publish the file with `php artisan vendor:publish --tag=loom-config`.

!!! warning "Cached routes freeze the URL"
    After `php artisan route:cache`, changing `LOOM_PATH`, `LOOM_DOMAIN` or `LOOM_UI_ENABLED` does nothing until `php artisan route:clear`. The environment guard is re-checked on every request.

## What the UI doesn't do

- It is read-only: nothing you click changes code, the index or your app.
- It shows what the scan found. A dispatch Loom couldn't resolve is in `unresolved_dispatches`, not the chain; see [Why was my code missed?](why-was-my-code-missed.md).
- It is a development tool. Install with `--dev` and don't expose it in production.
