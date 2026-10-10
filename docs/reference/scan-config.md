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
| `scan.discover_routes` | `true` | Also read route files that Laravel loads from a path: `loadRoutesFrom()`, `Route::group()` with a file path and the `web`, `api` and `commands` paths of `withRouting()` in `bootstrap/app.php`. See [Route files](#route-files). `false` reads only `scan.route_paths`. |
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
| `Modules/Billing/routes/web.php` | Nothing, when a provider loads it with `loadRoutesFrom()`. Otherwise `scan.route_paths`: `['routes', 'Modules/*/routes']` |

Directories that don't exist are ignored. If none exist, `loom:scan` exits with `1`.

## Route files

Laravel loads a route file with `require`, from any path. Loom reads the files under `scan.route_paths` (default `routes/`) and, while `scan.discover_routes` is on, every file these calls load by a path it can resolve statically:

| Call | Path argument |
| --- | --- |
| `$this->loadRoutesFrom($path)` in a service provider | `$path` |
| `Route::group($attributes, $path)` and `Route::...->group($path)` | `$path`, or an array of paths |
| `->withRouting(web:, api:, commands:)` in `bootstrap/app.php` | a path, or for `web` and `api` an array of paths |
| `->withCommands([...])` | file entries; directories are command paths and are skipped |

Paths resolve when they are built from string literals, `.` concatenation, `__DIR__`, `DIRECTORY_SEPARATOR`, `dirname()`, `base_path()` and `app_path()` (assuming the default `app/` directory). A discovered route file is searched for further loading calls.

Loom looks for these calls in the files under `scan.paths`, the route files, `bootstrap/app.php`, and the providers listed in `bootstrap/providers.php` and `config/app.php` (located through `composer.json` PSR-4), so a module provider outside `scan.paths` is still found when the app registers it.

The final set is `scan.route_paths` plus the discovered files, minus `scan.exclude`. A path that cannot be followed is never dropped silently: the summary line counts it and `loom:scan -v` lists the file, line and reason.

| Reason | Meaning |
| --- | --- |
| path is not statically resolvable | The expression uses a variable, a method call, a config value or another runtime value. |
| relative path depends on the working directory | A path such as `'routes/x.php'` with no `__DIR__` or `base_path()`. |
| path is outside the project root | The file is not under the project root. |
| no such file | The path resolves but names no file. |

Routes in a loaded file get no prefix, name or middleware from the group that loads it, since Loom reads each file on its own.

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
