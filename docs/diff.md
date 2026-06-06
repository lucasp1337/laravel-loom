# Diffing two indexes

`loom:diff` answers one question: **what eventually changed between two Loom indexes?**

It takes two already-built `index.json` files and reports a *semantic* diff — which events, listeners, jobs, observers, scheduled tasks, and dispatch sites were added, removed, or changed. It does not run any scanners and does not need your source tree; it reads JSON and compares it. Generate the two indexes however you like (a baseline committed to the repo, an artifact from `main`, the output of two `loom:scan` runs) and hand the files to `loom:diff`.

The diff is deterministic and order-independent: entries are matched by identity and sorted, so two diffs of the same pair of indexes produce byte-identical output. That makes it safe to snapshot in CI.

## Usage

```bash
php artisan loom:diff {old} {new} {--format=text}
```

```bash
# Compare a committed baseline against a fresh scan
php artisan loom:scan
php artisan loom:diff .loom/baseline.json storage/loom/index.json
```

`old` and `new` are paths to two index JSON files. Order matters: entries present in `new` but not `old` are **added**; entries in `old` but not `new` are **removed**.

## Output formats

`--format` selects how the diff is rendered. The semantic content is identical across formats; only the presentation differs.

| `--format` | Output                                              | Use for                          |
| ---------- | --------------------------------------------------- | -------------------------------- |
| `text`     | ANSI-colored terminal diff (default)                | Reading at a terminal            |
| `json`     | Machine-readable projection                         | Feeding another tool or an agent |
| `markdown` | GitHub-flavored Markdown                            | Pasting into a PR comment        |

A diff with no changes renders as `No semantic changes.` (text and markdown) or `{}` (json), and exits `0`.

### text

The default. Each non-empty section is a bold heading; `+` marks additions (green), `-` removals (red), `~` changed entries (yellow). Changed entries list their field changes and any members added to or removed from cross-link arrays.

```text
events
  + App\Events\InvoicePaid
  ~ App\Events\OrderPlaced
      handled_by:
        + {"listener":"App\Listeners\NotifyWarehouse","method":"handle"}

listeners
  - App\Listeners\LegacyAudit
  ~ App\Listeners\SendOrderConfirmation
      queued: false -> true
```

### json

A plain-array projection encoded as pretty JSON. Empty sections are omitted; an all-empty diff is `{}`. Each section carries `added`, `removed`, and `changed`; each changed entry carries its `identity`, `field_changes`, and `sublist_changes`.

```json
{
  "events": {
    "added": [
      { "id": "App\\Events\\InvoicePaid", "fqcn": "App\\Events\\InvoicePaid", "kind": "class", "file": "app/Events/InvoicePaid.php", "line": 11, "dispatched_from": [], "handled_by": [] }
    ],
    "removed": [],
    "changed": [
      {
        "identity": "App\\Events\\OrderPlaced",
        "field_changes": [],
        "sublist_changes": [
          {
            "field": "handled_by",
            "added": [ { "listener": "App\\Listeners\\NotifyWarehouse", "method": "handle" } ],
            "removed": []
          }
        ]
      }
    ]
  },
  "listeners": {
    "added": [],
    "removed": [
      { "fqcn": "App\\Listeners\\LegacyAudit", "...": "..." }
    ],
    "changed": [
      {
        "identity": "App\\Listeners\\SendOrderConfirmation",
        "field_changes": [ { "field": "queued", "old": false, "new": true } ],
        "sublist_changes": []
      }
    ]
  }
}
```

`added` and `removed` carry the full entry as it appeared in the source index, so the JSON form is self-contained — a consumer never has to re-open the original files.

### markdown

A `##` heading per non-empty section with bullet lists, ready to paste into a PR comment. Field changes use `→`; sublist members are prefixed `+` / `-`.

```markdown
## events

- **added** `App\Events\InvoicePaid`
- **changed** `App\Events\OrderPlaced`
  - `handled_by`:
    - `+` `{"listener":"App\Listeners\NotifyWarehouse","method":"handle"}`

## listeners

- **removed** `App\Listeners\LegacyAudit`
- **changed** `App\Listeners\SendOrderConfirmation`
  - `queued`: `false` → `true`
```

## Exit codes

`loom:diff` follows the `git diff --exit-code` convention: a diff that *finds* changes is a successful run, not an error.

| Code | Meaning                                                                       |
| ---- | ----------------------------------------------------------------------------- |
| `0`  | No semantic changes.                                                          |
| `1`  | Semantic changes exist. Informational — **not** a failure.                    |
| `2`  | Input error: a path isn't a file, the JSON is invalid, the top level isn't an object, or `--format` is unknown. |

## In CI

The exit-code split makes `loom:diff` a drift detector. Commit a baseline index (or fetch one from `main`), scan the PR branch, and diff:

```yaml
- run: php artisan loom:scan
- name: Report architectural drift
  run: |
    php artisan loom:diff .loom/baseline.json storage/loom/index.json --format=markdown > drift.md
    code=$?
    if [ "$code" -eq 1 ]; then
      gh pr comment "$PR" --body-file drift.md   # changes found — post them
    elif [ "$code" -eq 2 ]; then
      exit 1                                       # real error — fail the job
    fi
```

Exit `1` is the interesting case: the architecture moved, so surface the markdown diff on the PR. Reserve a hard failure for exit `2`. If you want any drift to *block* a merge, treat exit `1` as a failure instead — that's a policy choice the exit code leaves open.

## What counts as a change

`loom:diff` compares only the meaning of the index, not the bookkeeping around it. These top-level fields are **ignored**: `scanned_at`, `loom_version`, `laravel_version`, and the whole `stats` block (it's derived from the sections). A scan that differs only in timestamp or version produces an empty diff.

Within each section, entries are matched by an **identity** so the diff can tell "this entry changed" apart from "one entry was removed and a different one added":

| Section                                                | Matched by                          |
| ------------------------------------------------------ | ----------------------------------- |
| events, listeners, jobs, mailables, notifications      | `fqcn`                              |
| observers                                              | `(fqcn, observes)`                  |
| model_events                                           | `id`                                |
| scheduled                                              | `(file, line, kind, target)`        |
| unresolved_dispatches                                  | `(file, line, expression)`          |
| closure_listeners                                      | `(file, line, event, registration)` |

Once two entries are matched, their semantic fields are compared and any difference becomes a field change. Cross-link arrays — `handled_by`, `handles`, `dispatches`, `dispatched_from`, `sent_from`, `notified_from`, `hooks`, `constraints`, `channels` — are compared by membership: a member is reported as added or removed, never as an in-place edit. A change to a dispatch site's `overrides` (or a notification dispatch site's `channels`) surfaces as that member being removed and re-added, since those fields are part of the member's identity.

Two deliberate wrinkles:

- **`notifications.channels` is order-sensitive.** Reordering the channels in a `via()` literal is a change, because delivery order can matter.
- **Closure listeners are add/remove only.** A closure has no class name to anchor an in-place comparison, so there is no "changed" bucket for the `closure_listeners` section — a touched closure shows as the old one removed and a new one added.

For the field-by-field shape of each section that `loom:diff` reads, see the [Schema reference](schema.md).

## See also

- [Checking an index](check.md) — the sibling command: `loom:diff` reports what changed; [`loom:check`](check.md) decides whether the current index passes policy and exits non-zero if not.
</content>
</invoke>
