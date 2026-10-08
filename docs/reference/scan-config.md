# Scan configuration

Keys under `scan` in `config/loom.php` decide which directories `loom:scan` walks. Publish the file to change them:

```bash
php artisan vendor:publish --tag=loom-config
```

## Keys

| Key | Default | Meaning |
| --- | --- | --- |
| `scan.paths` | `['app']` | Directories to scan, relative to the project root. A `*` matches within one path segment (`Modules/*`). Paths outside the project root are rejected. |
| `scan.psr4_paths` | `false` | When `true`, also scans every directory in `autoload.psr-4` of your `composer.json`. `autoload-dev` is not included. |
| `scan.exclude` | `[]` | Globs relative to the project root. A match removes the file, or everything under the matching directory, from every scanner. |

`loom:scan --path=DIR` replaces `scan.paths` for one run. `scan.psr4_paths` and `scan.exclude` still apply.

## How directories resolve

Convention directories resolve inside each scan directory: `Events/`, `Listeners/`, `Jobs/`, `Mail/` and `Notifications/` are looked for directly under every entry of `scan.paths`. Everything else (dispatch sites, `$listen` arrays, observers, `Schedule::` calls, `Console/Kernel.php`) is found anywhere under a scan directory.

| Layout | `scan.paths` |
| --- | --- |
| Default Laravel | `['app']` |
| `Modules/Billing/Events/...` | `['app', 'Modules/*']` |
| nwidart/laravel-modules, `Modules/Billing/app/Events/...` | `['app', 'Modules/*/app']` |
| `lib/Domain/Events/...` | `['lib/Domain']` |

Directories that don't exist are ignored. If none exist, `loom:scan` exits with `1`.

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

`*` and `?` never cross a `/`; `**` does. Matching is case-sensitive. Excludes also apply to `routes/*.php`, `routes/console.php` and `bootstrap/app.php`.
