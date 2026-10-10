# Check rules and output formats

Every flag, rule key, exit code and output format of `loom:check` and `loom:diff`. For a walkthrough, see [Gate your CI](../guides/gate-your-ci.md). Both commands read finished `index.json` files; they run no scanners.

## Commands

```bash
php artisan loom:check {index?} {--baseline=} {--strict} {--skip=*} {--format=text}
php artisan loom:diff {old} {new} {--format=text}
```

`index` defaults to `storage/loom/index.json`. For `loom:diff`, order matters: entries in `new` but not `old` are added, the reverse are removed.

| Flag | Command | Effect |
| --- | --- | --- |
| `--baseline=PATH` | check | Previous index for growth checks. Only `unresolved-dispatches` reads it. |
| `--strict` | check | Any unresolved dispatch fails, not just new ones. Overrides `--baseline`. |
| `--skip=KEY` | check | Skip a rule. Repeatable. An unknown key exits `2`. |
| `--format=` | both | `text` (default), `json` or `markdown`. An unknown value exits `2`. |

## Rules

The run fails if any rule that ran has a violation.

| Key | Fails when | What to do |
| --- | --- | --- |
| `schema` | The index doesn't validate against the schema. | Re-run `loom:scan`; regenerate a hand-edited or foreign file. |
| `orphan-listeners` | A listener handles no events. | Type-hint the event on `handle()`, register the listener, or delete it. |
| `orphan-events` | An event is never dispatched and never handled. | Delete it, or add the dispatch or listener that was meant to exist. |
| `unresolved-dispatches` | See below. | Make the dispatched class a literal class name, or accept it into the baseline. |
| `cyclic-dispatch` | A chain of dispatches loops back to its start. | Break one link in the reported chain. |

`unresolved-dispatches` polices what a branch adds, not inherited debt:

| Flags | Behaviour |
| --- | --- |
| Neither | No-op: growth can't be measured without a reference. |
| `--baseline=PATH` | Fails on any unresolved dispatch not in the baseline. |
| `--strict` | Fails on every unresolved dispatch. |

!!! warning "A green gate can be enforcing nothing"
    Plain `loom:check` counts this rule as passed while checking nothing. With a baseline, entries match by file, line and expression, so moving code makes old entries look new.

`cyclic-dispatch` builds a graph with a node per event and job and an edge wherever a handler dispatches another. It reports one representative cycle per back-edge, so fix the reported chain and re-run to surface the next.

## Exit codes

| Command | Code | Meaning |
| --- | --- | --- |
| check | `0` | Every rule that ran passed. |
| check | `1` | A rule found a violation. |
| check | `2` | Nothing was checked: bad path, invalid JSON, unknown `--format` or `--skip` key. Fail the job too. |
| diff | `0` | No semantic changes. |
| diff | `1` | Changes exist. Not an error; fail only if drift should block. |
| diff | `2` | A path isn't a file, the JSON is invalid, or `--format` is unknown. |

## Check output

The verdict is the same in every format.

- **text**: rules with violations, then a count; a pass prints `All checks passed.`

  ```text
  orphan-events — Every event is dispatched or handled
    ✗ Event App\Events\OrderCancelled is never dispatched and never handled.
  cyclic-dispatch — No cyclic event/job dispatch chains
    ✗ Cyclic dispatch: App\Events\InventoryAdjusted → App\Events\OrderPlaced → App\Events\InventoryAdjusted
  2 violation(s) across 2 rule(s).
  ```

- **json**: `{ "passed", "violation_count", "rules": [...] }`. Every rule appears, skipped ones included, as `{ "key", "description", "skipped", "violations": [{ "message", "context" }] }`, so a consumer can tell what was enforced. `context` carries `fqcn`, `file` and `line`, or `reason`, `file`, `line` and `expression` for unresolved dispatches, or `cycle` (a list of class names).
- **markdown**: a `## loom:check` heading and one `###` section per failing rule with one bullet per violation, ready for a PR comment.

## Diff output

Entries added, removed or changed, sorted, so the same pair of indexes always gives the same output. No changes prints `No semantic changes.` (text, markdown) or `{}` (json).

- **text**: `+` added, `-` removed, `~` changed with changed members under it. Added and removed entries print as the full JSON entry.
- **json**: per section `{ "added", "removed", "changed" }`; empty sections are omitted. A changed entry is `{ "identity", "field_changes", "sublist_changes": [{ "field", "added", "removed" }] }`.
- **markdown**: a `##` heading per section; field changes use `→` and members are prefixed `+` or `-`.

`scanned_at`, `loom_version`, `laravel_version` and `stats` are ignored. Entries are matched by identity:

| Section | Matched by |
| --- | --- |
| events, listeners, jobs, mailables, notifications | `fqcn` |
| observers | `fqcn` and `observes` |
| model_events | `id` |
| scheduled_tasks | `file`, `line`, `kind`, `target` |
| unresolved_dispatches | `file`, `line`, `expression` |
| closure_listeners | `file`, `line`, `event`, `registration` |

Cross-link arrays (`handled_by`, `handles`, `dispatches`, `dispatched_from` and similar) compare by membership. Reordering `notifications.channels` counts as a change, since delivery order can matter, and closure listeners are add or remove only. Field shapes are in the [schema reference](schema.md).
