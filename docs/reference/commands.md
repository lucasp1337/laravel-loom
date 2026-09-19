# Commands

Loom adds five Artisan commands. All of them read or write a single snapshot, by default `storage/loom/index.json`.

| Command | What it does |
| --- | --- |
| `loom:scan` | Scans the app and writes the index |
| `loom:show` | Prints the index, optionally filtered |
| `loom:diff` | Compares two indexes |
| `loom:check` | Runs policy checks against an index (CI gate) |
| `loom:mcp` | Serves the index to AI agents over stdio |

## loom:scan

```bash
php artisan loom:scan
```

Statically parses the app (no boot of your routes or queues), validates the result against the [schema](schema.md) and writes `storage/loom/index.json`. It takes no arguments or options.

| Exit code | Meaning |
| --- | --- |
| `0` | Index written |
| `1` | The generated index failed schema validation; nothing is written |

## loom:show

```bash
php artisan loom:show [filter]
```

Prints `storage/loom/index.json` as pretty JSON.

| Argument | Meaning |
| --- | --- |
| `filter` | Optional substring. Keeps only `events`, `listeners`, `observers` and `model_events` entries whose JSON contains it. Other sections are printed unfiltered. |

| Exit code | Meaning |
| --- | --- |
| `0` | Printed |
| `1` | Index missing, unreadable or not valid JSON |

## loom:diff

```bash
php artisan loom:diff old.json new.json --format=markdown
```

Compares two index files semantically: added, removed and changed entities, not a text diff.

| Argument | Meaning |
| --- | --- |
| `old` | Path to the old `index.json` |
| `new` | Path to the new `index.json` |

| Option | Default | Meaning |
| --- | --- | --- |
| `--format` | `text` | `text`, `json` or `markdown` |

| Exit code | Meaning |
| --- | --- |
| `0` | No changes |
| `1` | Changes found (a successful run, like `git diff --exit-code`) |
| `2` | Error: bad path, invalid JSON or unknown format |

## loom:check

```bash
php artisan loom:check --strict --baseline=main-index.json
```

Runs the policy rules against an index and fails when one is violated. The rules and output formats are described in [Check rules and formats](check-rules-and-formats.md).

| Argument | Default | Meaning |
| --- | --- | --- |
| `index` | `storage/loom/index.json` | Path to the index to check |

| Option | Default | Meaning |
| --- | --- | --- |
| `--baseline` | none | Path to an older index, for growth checks |
| `--strict` | off | Treat any unresolved dispatch as a failure |
| `--skip` | none | Rule key to skip; repeat for several |
| `--format` | `text` | `text`, `json` or `markdown` |

| Exit code | Meaning |
| --- | --- |
| `0` | All checks passed |
| `1` | At least one rule was violated |
| `2` | Error: bad path, invalid JSON, unknown format or unknown rule key |

## loom:mcp

```bash
php artisan loom:mcp
```

Starts a read-only [MCP](https://modelcontextprotocol.io) server over stdio. It serves `storage/loom/index.json`, runs `loom:scan` first if the file is missing, and reloads when the file changes.

| Option | Meaning |
| --- | --- |
| `--snapshot=PATH` | Serve this index file instead of the default |
| `--scan` | Run `loom:scan` before serving |
| `--no-scan` | Never auto-scan; the snapshot must already exist |

| Exit code | Meaning |
| --- | --- |
| `0` | Server stopped normally |
| `1` | The MCP server is not registered |

## Configuration

Loom has few settings, and the scan, show, diff and check commands need none. `index_path` (env `LOOM_INDEX_PATH`) sets where the snapshot lives for the CLI, the MCP server and the UI; `null` means `storage/loom/index.json`. Set `ui.index_path` only to point the UI at a different file. Every UI key, with defaults, is in [UI configuration](ui-config.md). Publish the file with `php artisan vendor:publish --tag=loom-config`.
