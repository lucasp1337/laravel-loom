# Browse the UI

By the end of this page you'll have `/loom` open in your browser, you'll have followed `OrderPlaced` through every listener and job it triggers, and you'll know how to open the UI on a staging box.

You've run `php artisan loom:scan` and have an index on disk. The examples use a checkout app where `OrderPlaced` fires from `OrderController` and is handled by `SendOrderConfirmation` and `ReserveStock`.

## See what happens when OrderPlaced fires

Start your app locally and open the UI.

```bash
php artisan serve
```

```text
http://localhost:8000/loom
```

You land on a dashboard: a count for each primitive Loom found, an orphans card, and the events with the biggest fan-out. Click **Events** in the sidebar, then `OrderPlaced`. The event page lists the places that dispatch it, the listeners that handle it, and what those listeners dispatch next.

Click **View chain**. The chain view draws the whole trail as a graph, one column per hop:

- `OrderPlaced` is the root on the left.
- `SendOrderConfirmation` and `ReserveStock` sit one column right, as listener nodes.
- Whatever those listeners dispatch, such as an `InventoryAdjusted` event or a `NotifyWarehouse` job, sits one column further right.

Click any node to read its facts in the side panel. That answers "what happens when `OrderPlaced` fires?" without opening a single file.

!!! warning "The UI never scans"
    It reads the file `loom:scan` wrote. If you change code and reload, you see the old picture until you scan again. A banner tells you when that has happened ([see below](#when-the-page-isnt-what-you-expect)).

## Read the chain view

The toolbar has a depth selector from 1 to 6. Depth counts hops: depth 1 shows the event and its handlers, depth 2 adds the events those handlers dispatch and their handlers, and so on. The default is 3.

Two interactions change what you see:

- **Click** a node to select it. The panel shows its facts and a **Go to page** link.
- **Double-click** a node with children to collapse it. It gets a double border and a `⊕` in its label. Double-click again to expand it.

Changing the depth resets any collapsed nodes. The depth and the selected node are kept in the address bar (`?depth=4`), so you can paste the link to a colleague.

Two banners explain a graph that looks shorter than you expected:

| Message | What it means | What to do |
| --- | --- | --- |
| `Depth limit reached. Raise the depth to expand the remaining events.` | An event at the edge has handlers you aren't seeing | Pick a higher depth |
| `No handlers registered for this event.` | Nothing in the index handles the root event | Check the [orphan list](#the-dashboard) or [why your code was missed](why-was-my-code-missed.md) |

A node labelled `↺ already shown` is an event the graph drew earlier on another branch. It stops there, with no children, so a shared event isn't drawn twice and a loop can't draw forever.

!!! warning "Already shown isn't always a loop"
    The marker appears whenever an event repeats, including a harmless one reached by two different listeners. It doesn't tell you the chain is circular. To find real dispatch cycles, run [`loom:check`](../reference/check-rules-and-formats.md), whose `cyclic-dispatch` rule reports only true loops.

!!! warning "Some things aren't followed"
    Jobs, mailables and notifications are leaves: the graph shows that a listener dispatches or sends them, but not what they do afterwards. Model observers don't appear as event handlers either, so a model event chain (`saved`, `deleted`) never shows up here.

Dispatches are attributed to a whole class, not to the method that makes them. If `ReserveStock` has two methods and only one dispatches `InventoryAdjusted`, the graph still hangs it off `ReserveStock`.

## Find things in the lists

Every sidebar section is a table. Type in the filter box to narrow it by class name, namespace or file path. Click a column header to sort, and click it again to reverse the order. Long lists page at 50 rows.

The address bar keeps the whole view, so this link is a saved search:

```text
http://localhost:8000/loom/listeners?q=Order&sort=dispatches&dir=desc&page=1
```

It shows listeners matching `Order`, busiest dispatchers first. A row marked `orphan` is unconnected: an event nobody handles or dispatches, or a listener that handles no event.

Sections without a detail page (`scheduled`, `routes`, `closure_listeners`, `model_events`, `unresolved_dispatches`) are read straight from the table.

## Jump anywhere

Press `Ctrl K` (`Cmd K` on a Mac) or `/` to open the command palette, then type part of a class name, a route or a file path. The palette searches events, listeners, observers, jobs, mailables, notifications, routes and closure listeners, ranks exact and prefix matches first, and shows at most 20 hits.

| Keys | Action |
| --- | --- |
| `Ctrl K` / `Cmd K`, or `/` | Open the palette |
| Up, Down, Enter | Move through results and open one |
| Esc | Close the palette or the navigation drawer; deselect a chain node |
| `g` then `d` | Dashboard |
| `g` then `e` | Events |
| `g` then `l` | Listeners |
| `g` then `o` | Observers |
| `g` then `j` | Jobs |
| `g` then `c` | Closure listeners |
| `g` then `m` | Mailables |
| `g` then `n` | Notifications |
| `g` then `r` | Routes |

Press the second key within about a second of `g`. The `g` shortcuts are ignored while you're typing in an input, and they only exist for sections the current index contains.

## The dashboard

The dashboard's counters link to each section. The Orphans card lists up to eight events with no handlers, listeners with no event, and unresolved dispatches, so it's the fastest place to see what Loom couldn't connect. The fan-out card ranks events by handler count and how many events they reach downstream. Its links open the chain view.

## When the page isn't what you expect

| You see | Why | What to do |
| --- | --- | --- |
| 503 `No index found` | Nothing at the index path | Run `php artisan loom:scan` and reload. The page has a copy button for the command |
| 503 `Index could not be loaded` | The file exists but isn't a valid index | Run `php artisan loom:scan` again; the message shows the parse error |
| 403 | The `viewLoom` gate said no | See [Open it on staging](#open-it-on-staging) |
| 404 on `/loom` | The environment isn't in `ui.environments`, or the UI is disabled | See [Open it on staging](#open-it-on-staging) |
| 404 | The class isn't in the index | Check the spelling, or scan again if you just added it |
| `Index is 6 days older than your last commit to app/` | Code changed after the scan | Run `php artisan loom:scan` |

The stale banner compares the index's scan time with the newest git commit touching `app/`. It only works in a git checkout with `git` on the path; anywhere else it stays silent, which isn't proof the index is fresh. The answer is cached for 30 seconds, so a commit can take that long to show.

!!! warning "Uncommitted changes don't trigger the banner"
    The comparison uses commits, not the working tree. Edit a file, don't commit, and the UI shows no banner even though the index is behind.

## Open it on staging

By default the UI exists only in the `local` environment. In any other environment nothing is registered, so `/loom` returns 404. The index lists your file paths and internal class names, so this is deliberate.

To open it on staging, add the environment to `config/loom.php`, then define a `viewLoom` gate in `App\Providers\AppServiceProvider`. Yours replaces the default.

```php
'ui' => [
    'environments' => ['local', 'staging'],
],
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewLoom', fn (?User $user): bool => app()->environment('staging')
        && in_array($user?->email, ['dev@shop.test', 'lead@shop.test'], true));
}
```

Only those two signed-in users can open `/loom` on staging. A guest passes `null`, which is not in the list, so guests get 403. A 403 means the UI is mounted and the gate refused you; a 404 means the environment isn't listed.

The gate runs on the first page load and again on every click inside a page, so revoking access takes effect on the next interaction. The stylesheet and script files under `/loom/assets` are the exception: they're served outside the gate so the 403 page can still load its own styles.

!!! warning "The gate is the only lock"
    `ui.middleware` runs before the gate, but removing the gate isn't an option: the check always runs. `production` is ignored in `ui.environments` unless `ui.allow_in_production` is `true`. Leave it off; if you must, keep a strict gate.

## Change the URL, turn it off, publish the config

Set these in `.env` for the common cases.

```dotenv
LOOM_PATH=architecture
LOOM_DOMAIN=internal.shop.test
LOOM_UI_ENABLED=false
```

The first serves the UI at `/architecture` instead of `/loom`, the second restricts it to one domain, and the third removes the UI entirely: no routes, no gate, no pages. A disabled UI ignores the other two.

To change anything else, publish the config file:

```bash
php artisan vendor:publish --tag=loom-config
```

That writes `config/loom.php`. Every key is in [UI configuration](../reference/ui-config.md).

!!! warning "Cached routes freeze the UI's URL"
    With `php artisan route:cache`, the UI's routes are frozen into the cache file as they were at cache time. Change `LOOM_PATH`, `LOOM_DOMAIN` or `LOOM_UI_ENABLED` and nothing happens until you run `php artisan route:clear` (or re-cache). The environment guard is the exception: it is re-checked on every request. The same applies if you cached before Loom was installed: `/loom` returns 404.

## What the UI doesn't do

- **It's read-only.** Nothing you click changes code, the index or your app.
- **It shows what the scan found.** A dispatch Loom couldn't resolve is in the `unresolved_dispatches` list, not in the chain. [Why was my code missed?](why-was-my-code-missed.md) covers the causes.
- **It's a development tool.** Installed with `--dev`, it isn't in a `composer install --no-dev` build. Install it with `--dev` and don't expose it in production. If you install it there anyway, leave `ui.allow_in_production` off.

You're done when `/loom` shows your counts and the chain for `OrderPlaced` lists the listeners you expect.
