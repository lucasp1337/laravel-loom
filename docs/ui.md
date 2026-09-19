# Browser UI

A read-only browser view of the index, served by your Laravel app at `/loom`. It reads the snapshot written by `loom:scan`; it never scans, writes, or dispatches anything.

## Requirements

- A written index at `storage/loom/index.json` (or the path in `loom.index_path`). Run `php artisan loom:scan` first.
- Livewire 3 (installed as a dependency of the package).
- Access allowed by the `viewLoom` gate (see below).

If the index file is missing, every page renders a "No index found" state with the `php artisan loom:scan` command to copy. If the file exists but is not a valid index, the page shows "Index could not be loaded" with the error and the same command. The UI does not auto-scan.

If the newest commit touching `app/` is later than the index's `scanned_at`, a banner says the index is stale. This uses `git` in the app root; without a git checkout, no banner is shown.

## Enable and disable

The UI is on by default. Turn it off with an environment variable or config:

```dotenv
LOOM_UI_ENABLED=false
```

When disabled, no routes, views, gate, or Livewire components are registered. Only the `loom-config` publish tag stays available.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=loom-config
```

This writes `config/loom.php`. The `ui` block:

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `LOOM_UI_ENABLED` | `true` | Mount the UI. |
| `path` | `LOOM_PATH` | `loom` | URI prefix. |
| `domain` | `LOOM_DOMAIN` | `null` | Optional domain to serve from. |
| `middleware` | none | `['web']` | Middleware applied before the `viewLoom` gate check. The gate check always runs. |
| `index_path` | none | `null` | Optional UI-only override of the top-level `loom.index_path` (env `LOOM_INDEX_PATH`, default `storage/loom/index.json`). |
| `chain_depth` | none | `3` | Default depth on the chain page, clamped to 1-5. |

If routes are cached (`route:cache`), the UI routes are not registered by the package on that boot; clear the cache or cache after enabling.

## Access control

The UI is guarded by the `viewLoom` gate. The default definition allows access only when `app()->environment('local')` is true, so it returns a 403 page anywhere else.

To open it on staging, define the gate yourself. An app-defined `viewLoom` wins regardless of provider order:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewLoom', function (?User $user = null): bool {
        return app()->environment('staging')
            && $user?->hasRole('developer');
    });
}
```

Livewire update requests re-run the gate, so revoking access applies to open pages on their next interaction. Static assets (`/loom/assets/*`) are served outside the gate so the 403 page can load its stylesheet.

## URL scheme

All paths are under the configured prefix (`/loom` by default).

| URL | Screen |
| --- | --- |
| `/loom` | Dashboard |
| `/loom/{section}` | Section table |
| `/loom/events/{fqcn}` | Event detail |
| `/loom/{section}/{fqcn}` | Detail for a listener, observer, job, mailable, or notification |
| `/loom/chain/{fqcn}` | Chain graph for an event |

`{section}` is one of the index sections: `events`, `listeners`, `observers`, `model_events`, `jobs`, `unresolved_dispatches`, `closure_listeners`, `scheduled`, `mailables`, `notifications`, `routes`. FQCNs are written with backslashes in the path. Section tables keep search (`q`), sort (`sort`, `dir`) and `page` in the query string, so views are linkable.

## Screens

- **Dashboard.** Index metadata and per-section counts.
- **Section tables.** Searchable, sortable, paginated. Rows the index shows as unconnected are marked `orphan`.
- **Entity detail.** Facts about one class plus its links to handlers, dispatch sites, and related entities.
- **Chain.** See below.
- **Command palette.** Search across entities; open with Ctrl/Cmd+K or `/`.

## Chain view

`/loom/chain/{fqcn}` draws what follows an event: its handlers, the events those handlers dispatch, and so on, as a graph.

- Depth is selectable from 1 to 5; the default comes from `chain_depth`. It is also kept in the query string (`depth`, `node`).
- Click a node to see its facts in a side panel; double-click to collapse or expand its children.
- A node already drawn earlier in the chain is shown as "already shown" instead of drawing a loop edge.
- When the depth limit cuts the chain off, a caption says so.
- A hidden list of nodes exists for screen readers.

The graph is drawn with a vendored copy of [cytoscape](https://js.cytoscape.org) (3.30.2, in `resources/dist/`), loaded on the chain page only. No CDN is used.

## Keyboard shortcuts

| Keys | Action |
| --- | --- |
| Ctrl/Cmd+K, or `/` | Open the command palette |
| Up / Down / Enter | Move through and open palette results |
| Esc | Close the palette or drawer; deselect a chain node |
| `g` then `d` | Dashboard |
| `g` then `e` / `l` / `o` / `j` / `c` / `m` / `n` / `r` | Events / listeners / observers / jobs / closure listeners / mailables / notifications / routes |

The `g` shortcuts only work for sections present in the index, and are ignored while typing in an input.

## Limits

- **Read-only.** No action in the UI changes code, the index, or application state.
- **Class-level dispatch attribution.** On the chain page, dispatches made from listeners, jobs, and closures are attributed to the class, not to a specific method.
- **Static data.** It shows what the scanners found. It inherits their limits, see [what Loom detects](what-loom-detects.md).
- **Development tool.** It is meant for local and staging use. Keep the default gate in production unless you have a reason to change it.
