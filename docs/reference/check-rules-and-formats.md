# Check rules and output formats

Every flag, rule key, exit code and output format of `loom:check` and `loom:diff`. For a walkthrough, see [Gate your CI](../guides/gate-your-ci.md).

Both commands read finished `index.json` files. They run no scanners and don't need your source tree.

## Commands

```bash
php artisan loom:check {index?} {--baseline=} {--strict} {--skip=*} {--format=text}
php artisan loom:diff {old} {new} {--format=text}
```

`index` defaults to `storage/loom/index.json`. For `loom:diff`, order matters: entries in `new` but not `old` are added, and entries in `old` but not `new` are removed.

| Flag | Command | Effect |
| --- | --- | --- |
| `--baseline=PATH` | check | Previous index for growth checks. Only `unresolved-dispatches` reads it. |
| `--strict` | check | Any unresolved dispatch fails, not just new ones. Overrides `--baseline`. |
| `--skip=KEY` | check | Skip a rule. Repeatable. An unknown key exits `2`. |
| `--format=` | both | `text` (default), `json` or `markdown`. An unknown value exits `2`. |

## Rules

A rule either passes or adds violations. The run fails if any rule that ran has one.

| Key | Fails when | What to do |
| --- | --- | --- |
| `schema` | The index doesn't validate against the Loom schema. | Re-run `loom:scan`. If the file was edited by hand or came from a different Loom version, regenerate it. |
| `orphan-listeners` | A listener handles no events. | Type-hint the event on `handle()`, register the listener, or delete it. |
| `orphan-events` | An event is never dispatched and never handled. | Delete it, or add the dispatch or listener that was meant to exist. |
| `unresolved-dispatches` | See below. | Make the dispatched class visible (a literal class name), or accept it into the baseline. |
| `cyclic-dispatch` | A chain of dispatches loops back to its start. | Break one link in the reported chain. |

### Unresolved dispatches, baseline and strict

`unresolved-dispatches` polices what a branch adds, not the debt it inherits.

| Flags | Behaviour |
| --- | --- |
| Neither `--baseline` nor `--strict` | No-op. It passes because growth can't be measured without a reference. |
| `--baseline=PATH` | Fails on any unresolved dispatch not in the baseline. |
| `--strict` | Fails on every unresolved dispatch, whatever the baseline says. |

!!! warning "A green gate can be enforcing nothing"
    Plain `loom:check` counts this rule as passed while checking nothing. With a baseline, entries match by file, line and expression, so moving code makes old entries look new.

### Cycle detection

`cyclic-dispatch` builds a graph with a node per event and job and an edge wherever a handler dispatches another. If a listener of `OrderPlaced` dispatches `InventoryAdjusted`, there is an edge `OrderPlaced → InventoryAdjusted`. Jobs join through their own dispatches.

!!! note "One cycle per run"
    It reports a representative cycle for each back-edge, not every possible cycle in a tangled region. Fix the reported chain, re-run, and the next one surfaces.

## Exit codes

| Command | Code | Meaning | What to do |
| --- | --- | --- | --- |
| check | `0` | Every rule that ran passed. | Nothing. |
| check | `1` | A rule found a violation. | Fix it or change the policy. Fail the job. |
| check | `2` | Nothing was checked: bad path, invalid JSON, unknown `--format`, unknown `--skip` key. | Fix the invocation. Fail the job too. |
| diff | `0` | No semantic changes. | Nothing. |
| diff | `1` | Changes exist. Not an error. | Post the diff. Fail only if you want drift to block. |
| diff | `2` | A path isn't a file, the JSON is invalid, or `--format` is unknown. | Fix the invocation. |

## Check output

The verdict is the same in every format.

### text

Rules with violations, then a count. Passing output is `All checks passed.`. Real terminal output is colored.

```text
orphan-listeners — Every listener handles at least one event
  ✗ Listener App\Listeners\LegacyAudit handles no events.
orphan-events — Every event is dispatched or handled
  ✗ Event App\Events\OrderCancelled is never dispatched and never handled.
unresolved-dispatches — No new unresolved dispatches (strict: none at all)
  ✗ Unresolved dispatch (dynamic_class_name) at app/Services/Checkout.php:14: event(new $eventClass())
cyclic-dispatch — No cyclic event/job dispatch chains
  ✗ Cyclic dispatch: App\Events\InventoryAdjusted → App\Events\OrderPlaced → App\Events\InventoryAdjusted
4 violation(s) across 4 rule(s).
```

### json

Every rule appears, including skipped ones, so a consumer can tell what was enforced. This run used `--baseline`:

```json
{
    "passed": false,
    "violation_count": 4,
    "rules": [
        {
            "key": "schema",
            "description": "Index validates against the JSON schema",
            "skipped": false,
            "violations": []
        },
        {
            "key": "orphan-listeners",
            "description": "Every listener handles at least one event",
            "skipped": false,
            "violations": [
                {
                    "message": "Listener App\\Listeners\\LegacyAudit handles no events.",
                    "context": {
                        "fqcn": "App\\Listeners\\LegacyAudit",
                        "file": "app/Listeners/LegacyAudit.php",
                        "line": 7
                    }
                }
            ]
        },
        {
            "key": "orphan-events",
            "description": "Every event is dispatched or handled",
            "skipped": false,
            "violations": [
                {
                    "message": "Event App\\Events\\OrderCancelled is never dispatched and never handled.",
                    "context": {
                        "fqcn": "App\\Events\\OrderCancelled"
                    }
                }
            ]
        },
        {
            "key": "unresolved-dispatches",
            "description": "No new unresolved dispatches (strict: none at all)",
            "skipped": false,
            "violations": [
                {
                    "message": "Unresolved dispatch (dynamic_class_name) at app/Services/Checkout.php:14: event(new $eventClass())",
                    "context": {
                        "reason": "dynamic_class_name",
                        "file": "app/Services/Checkout.php",
                        "line": 14,
                        "expression": "event(new $eventClass())"
                    }
                }
            ]
        },
        {
            "key": "cyclic-dispatch",
            "description": "No cyclic event/job dispatch chains",
            "skipped": false,
            "violations": [
                {
                    "message": "Cyclic dispatch: App\\Events\\InventoryAdjusted → App\\Events\\OrderPlaced → App\\Events\\InventoryAdjusted",
                    "context": {
                        "cycle": [
                            "App\\Events\\InventoryAdjusted",
                            "App\\Events\\OrderPlaced",
                            "App\\Events\\InventoryAdjusted"
                        ]
                    }
                }
            ]
        }
    ]
}
```

With `--skip=orphan-events`, that rule stays in the list but reports as skipped:

```json
{
    "key": "orphan-events",
    "description": "Every event is dispatched or handled",
    "skipped": true,
    "violations": []
}
```

### markdown

A `## loom:check` heading and one `###` section per failing rule, ready for a PR comment. A passing run prints `All checks passed.`.

```markdown
## loom:check

### orphan-listeners — Every listener handles at least one event
- Listener App\Listeners\LegacyAudit handles no events.

### orphan-events — Every event is dispatched or handled
- Event App\Events\OrderCancelled is never dispatched and never handled.

### unresolved-dispatches — No new unresolved dispatches (strict: none at all)
- Unresolved dispatch (dynamic_class_name) at app/Services/Checkout.php:14: event(new $eventClass())

### cyclic-dispatch — No cyclic event/job dispatch chains
- Cyclic dispatch: App\Events\InventoryAdjusted → App\Events\OrderPlaced → App\Events\InventoryAdjusted
```

## Diff output

`loom:diff` reports entries added, removed or changed, sorted, so the same pair of indexes always produces identical output. No changes prints `No semantic changes.` (text, markdown) or `{}` (json).

### text

`+` added, `-` removed, `~` changed, with the changed cross-link members listed under it. Added and removed entries print as the full JSON entry.

```text
events
  + {"id":"App\\Events\\OrderCancelled","fqcn":"App\\Events\\OrderCancelled","kind":"class","file":"app/Events/OrderCancelled.php","line":7,"dispatched_from":[],"handled_by":[]}
  ~ App\Events\OrderPlaced
      handled_by:
        + {"listener":"App\\Listeners\\ReserveStock","method":"handle"}
      dispatched_from:
        + {"file":"app/Listeners/RecountOrders.php","line":14,"method":"App\\Listeners\\RecountOrders::handle"}

unresolved_dispatches
  + {"file":"app/Services/Checkout.php","line":14,"expression":"event(new $eventClass())","reason":"dynamic_class_name"}
```

### json

Empty sections are omitted. Each section has `added`, `removed` and `changed`. Added and removed entries carry the full entry, so a consumer never reopens the source files. Here an event was removed and one of its dispatch sites went away:

```json
{
    "events": {
        "added": [],
        "removed": [
            {
                "id": "App\\Events\\InventoryAdjusted",
                "fqcn": "App\\Events\\InventoryAdjusted",
                "kind": "class",
                "file": "app/Events/InventoryAdjusted.php",
                "line": 7,
                "dispatched_from": [],
                "handled_by": []
            }
        ],
        "changed": [
            {
                "identity": "App\\Events\\OrderPlaced",
                "field_changes": [],
                "sublist_changes": [
                    {
                        "field": "dispatched_from",
                        "added": [],
                        "removed": [
                            {
                                "file": "app/Services/Checkout.php",
                                "line": 13,
                                "method": "App\\Services\\Checkout::finalize"
                            }
                        ]
                    }
                ]
            }
        ]
    }
}
```

### markdown

A `##` heading per section. Field changes use `→` and members are prefixed `+` or `-`.

```markdown
## events

- **removed** `{"id":"App\\Events\\InventoryAdjusted","fqcn":"App\\Events\\InventoryAdjusted","kind":"class","file":"app/Events/InventoryAdjusted.php","line":7,"dispatched_from":[],"handled_by":[]}`
- **changed** `App\Events\OrderPlaced`
  - `dispatched_from`:
    - `-` `{"file":"app/Services/Checkout.php","line":13,"method":"App\\Services\\Checkout::finalize"}`
```

## What the diff compares

`scanned_at`, `loom_version`, `laravel_version` and the whole `stats` block are ignored, so two scans that differ only in those produce an empty diff. Entries are matched by identity, which is how a diff tells "changed" from "removed and re-added".

| Section | Matched by |
| --- | --- |
| events, listeners, jobs, mailables, notifications | `fqcn` |
| observers | `fqcn` and `observes` |
| model_events | `id` |
| scheduled | `file`, `line`, `kind`, `target` |
| unresolved_dispatches | `file`, `line`, `expression` |
| closure_listeners | `file`, `line`, `event`, `registration` |

Cross-link arrays (`handled_by`, `handles`, `dispatches`, `dispatched_from` and similar) compare by membership: a member is added or removed, never edited in place.

!!! note "Two exceptions"
    Reordering `notifications.channels` counts as a change, since delivery order can matter. Closure listeners are add or remove only, because a closure has no class name to match on.

Field shapes for each section are in the [schema reference](schema.md).
