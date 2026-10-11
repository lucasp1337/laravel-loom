# Commands

Loom adds five Artisan commands. They read or write a single snapshot, by default `storage/loom/index.json`.

| Command | What it does |
| --- | --- |
| `loom:scan` | Scans the app and writes the index |
| `loom:show` | Prints the index, optionally filtered |
| `loom:diff` | Compares two indexes |
| `loom:check` | Runs policy checks against an index (CI gate) |
| `loom:mcp` | Serves the index to AI agents over stdio |

## loom:scan

```bash
php artisan loom:scan [--output=PATH] [--path=DIR ...] [--route-path=DIR ...] [-v]
```

Statically parses the app (no boot), validates the result against the [schema](schema.md) and writes the index. The directories it walks come from [`scan.*` config](scan-config.md).

| Option | Meaning |
| --- | --- |
| `--output=PATH` | Write the index here instead of `index_path`. A relative path is resolved from the project root. The MCP server and UI keep reading `index_path`. |
| `--path=DIR` | Scan this directory instead of `scan.paths`. Repeatable; relative to the project root; `*` globs allowed. |
| `--route-path=DIR` | Read routes from this directory instead of `scan.route_paths`. Same rules as `--path`. |
| `--no-discover-routes` | Don't follow route-file loading calls for this run, as if `scan.discover_routes` were `false`. Wins over the config value. |
| `-v` | List every skipped file with path, line and parser message, and every route-file path that could not be followed with its reason. |

When it finishes it prints the path written and one summary line with the entry count of each section, unresolved dispatches and skipped files:

```text
Loom index written to /var/www/shop/storage/loom/index.json
events: 1, listeners: 1, observers: 0, model events: 0, jobs: 1, unresolved dispatches: 1, closure listeners: 1, scheduled tasks: 0, mailables: 0, notifications: 0, routes: 1, skipped files: 1 (-v lists them)
```

A skipped file is one Loom could not read or parse. It is skipped by every scanner, counted once, and does not change the exit code. Without `-v` only the count is shown; with it each file is listed:

```text
Skipped files:
  app/Broken.php:5  Syntax error, unexpected '}', expecting T_VARIABLE
```

| Exit code | Meaning |
| --- | --- |
| `0` | Index written, with or without skipped files |
| `1` | A scan path is invalid or matches no directory, the index failed schema validation, or the output could not be written. Nothing is written. |

## loom:show

```bash
php artisan loom:show [filter]
```

Prints the index as pretty JSON.

| Argument | Meaning |
| --- | --- |
| `filter` | Optional substring. Keeps only `events`, `listeners`, `observers` and `model_events` entries whose JSON text contains it, so a class or method name works. Other sections print unfiltered. A filter containing `/` or `\` never matches, because the JSON text has them escaped. |

Exit `0` when printed, `1` when the index is missing, unreadable or not valid JSON.

## loom:diff

```bash
php artisan loom:diff old.json new.json --format=markdown
```

Compares two index files (`old` and `new`) semantically: added, removed and changed entities, not a text diff. `--format` is `text` (default), `json` or `markdown`.

| Exit code | Meaning |
| --- | --- |
| `0` | No changes |
| `1` | Changes found (a successful run, like `git diff --exit-code`) |
| `2` | Error: bad path, invalid JSON or unknown format |

## loom:check

```bash
php artisan loom:check [index] --strict --baseline=main-index.json
```

Runs the policy rules against an index and fails when one is violated.

| Argument or option | Default | Meaning |
| --- | --- | --- |
| `index` | `storage/loom/index.json` | Path to the index to check |
| `--baseline` | none | Path to an older index, for growth checks |
| `--strict` | off | Treat any unresolved dispatch as a failure |
| `--skip` | none | Rule key to skip; repeatable |
| `--format` | `text` | `text`, `json` or `markdown` |

| Exit code | Meaning |
| --- | --- |
| `0` | All checks passed |
| `1` | At least one rule was violated |
| `2` | Nothing was checked: bad path, invalid JSON, unknown format or unknown rule key |

Rules and formats are in [Check rules and formats](check-rules-and-formats.md).

## loom:mcp

```bash
php artisan loom:mcp
```

Starts a read-only [MCP](https://modelcontextprotocol.io) server over stdio. It serves the index, runs `loom:scan` first if the file is missing, and reloads when the file changes.

| Option | Meaning |
| --- | --- |
| `--snapshot=PATH` | Serve this index file instead of the default |
| `--scan` | Run `loom:scan` before serving |
| `--no-scan` | Never auto-scan; the snapshot must already exist |

Exit `0` when the server stops normally, `1` when `laravel/mcp` isn't installed, `mcp.enabled` is `false`, or the server is not registered.

## Configuration

Loom has few settings, and the show, diff and check commands need none. Which directories `loom:scan` walks is set by the `scan` keys in [Scan configuration](scan-config.md); every UI key is in [UI configuration](ui-config.md).

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `index_path` | `LOOM_INDEX_PATH` | `null` (`storage/loom/index.json`) | Where the snapshot lives for the CLI, MCP server and UI |
| `ui.index_path` | none | `null` | Point only the UI at a different file |
| `mcp.enabled` | `LOOM_MCP_ENABLED` | `true` | `false` turns `loom:mcp` off even when `laravel/mcp` is installed |

Publish the file with `php artisan vendor:publish --tag=loom-config`.
