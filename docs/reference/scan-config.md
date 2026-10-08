# Scan configuration

Keys under `scan` in `config/loom.php` decide which directories `loom:scan` walks. Publish the file to change them:

```bash
php artisan vendor:publish --tag=loom-config
```

## Keys

| Key | Default | Meaning |
| --- | --- | --- |
| `scan.paths` | `['app']` | Directories to scan, relative to the project root. A `*` matches within one path segment (`Modules/*`). Paths outside the project root are rejected. |
| `scan.route_paths` | `['routes']` | Directories holding route files, relative to the project root. `*` globs are allowed (`Modules/*/routes`). Read for `routes[]` and for dispatches inside route closures. Independent of `scan.paths`. |
| `scan.psr4_paths` | `false` | When `true`, also scans every directory in `autoload.psr-4` of your `composer.json`. `autoload-dev` is not included. |
| `scan.exclude` | `[]` | Globs relative to the project root. A match removes the file, or everything under the matching directory, from every scanner. |

`loom:scan --path=DIR` replaces `scan.paths` for one run, and `--route-path=DIR` replaces `scan.route_paths`. `scan.psr4_paths` and `scan.exclude` still apply.

## How directories resolve

Convention directories resolve inside each scan directory: `Events/`, `Listeners/`, `Jobs/`, `Mail/` and `Notifications/` are looked for directly under every entry of `scan.paths`. Everything else (dispatch sites, `$listen` arrays, observers, `Schedule::` calls, `Console/Kernel.php`) is found anywhere under a scan directory.

| Layout | `scan.paths` |
| --- | --- |
| Default Laravel | `['app']` |
| `Modules/Billing/Events/...` | `['app', 'Modules/*']` |
| `Modules/Billing/app/Events/...` | `['app', 'Modules/*/app']` |
| `lib/Domain/Events/...` | `['lib/Domain']` |
| `Modules/Billing/routes/web.php` | `scan.route_paths`: `['routes', 'Modules/*/routes']` |

Directories that don't exist are ignored. If none exist, `loom:scan` exits with `1`.

## Route files

Laravel loads a route file with `require`, from any path: a service provider's `loadRoutesFrom()`, `Route::group()` with a file path, or `bootstrap/app.php`. Loom does not follow those calls yet. It reads the files under `scan.route_paths` (default `routes/`), so a modular app lists its module route directories there, for example `['routes', 'Modules/*/routes']`.

## Locating classes

When a dispatch names a class that isn't in a convention directory, Loom finds its file through the PSR-4 map in your `composer.json` (`autoload.psr-4`), the same way Composer's autoloader does: longest matching prefix first, each directory of that prefix in order, then the empty prefix. Without a `composer.json`, or with no `psr-4` entry, Loom assumes `App\` maps to `app/`.

A located file must be inside a scan directory and not excluded, otherwise the class is dropped.

## Exclude globs

| Pattern | Matches |
| --- | --- |
| `app/Legacy` | The directory and everything under it |
| `app/*/Fakes/*.php` | One directory level between `app/` and `Fakes/` |
| `**/Fakes` | Any `Fakes` directory at any depth |
| `app/**/*.generated.php` | Generated files at any depth under `app/` |

`*` and `?` never cross a `/`; `**` does. Matching is case-sensitive. Excludes also apply to route files, `routes/console.php` and `bootstrap/app.php`.
