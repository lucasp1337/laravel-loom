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
| `--output=PATH` | Write the index here instead of `index_path`. The MCP server and UI keep reading `index_path`. |
| `--path=DIR` | Scan this directory instead of `scan.paths`. Repeatable; relative to the project root; `*` globs allowed. |
| `--route-path=DIR` | Read routes from this directory instead of `scan.route_paths`. Same rules as `--path`. |
| `--no-discover-routes` | Don't follow route-file loading calls for this run, as if `scan.discover_routes` were `false`. |
| `-v` | List every skipped file with path, line and parser message, and every route-file path that could not be followed with its reason. |

It prints the path written and one summary line with the entry count of each section, unresolved dispatches and skipped files. A skipped file could not be read or parsed; it is skipped by every scanner and does not change the exit code.

| Exit code | Meaning |
| --- | --- |
| `0` | Index written, with or without skipped files |
| `1` | A scan path is invalid or matches no directory, the index failed schema validation, or the output could not be written. Nothing is written. |

## loom:show

```bash
php artisan loom:show [filter]
```

Prints the index as pretty JSON. `filter` is an optional substring that keeps only `events`, `listeners`, `observers` and `model_events` entries whose JSON contains it; other sections print unfiltered. Exit `0`, or `1` when the index is missing, unreadable or invalid.

## loom:diff

```bash
php artisan loom:diff old.json new.json --format=markdown
```

Compares two index files (`old` and `new`) semantically: added, removed and changed entities. `--format` is `text` (default), `json` or `markdown`. Exit `0` for no changes, `1` for changes (a successful run, like `git diff --exit-code`), `2` for a bad path, invalid JSON or unknown format.

## loom:check

```bash
php artisan loom:check [index] --strict --baseline=main-index.json
```

Runs the policy rules against the index at `index` (default `storage/loom/index.json`) and fails when one is violated.

| Option | Default | Meaning |
| --- | --- | --- |
| `--baseline` | none | Path to an older index, for growth checks |
| `--strict` | off | Treat any unresolved dispatch as a failure |
| `--skip` | none | Rule key to skip; repeatable |
| `--format` | `text` | `text`, `json` or `markdown` |

Exit `0` when all checks pass, `1` when a rule is violated, `2` for a bad path, invalid JSON, unknown format or unknown rule key. Rules and formats are in [Check rules and formats](check-rules-and-formats.md).

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

`index_path` (env `LOOM_INDEX_PATH`) sets where the snapshot lives for the CLI, MCP server and UI; `null` means `storage/loom/index.json`. `ui.index_path` points the UI at a different file. `mcp.enabled` (env `LOOM_MCP_ENABLED`, default `true`) turns `loom:mcp` off even when `laravel/mcp` is installed. The `scan` keys are in [Scan configuration](scan-config.md) and the rest in [UI configuration](ui-config.md). Publish the file with `php artisan vendor:publish --tag=loom-config`.
