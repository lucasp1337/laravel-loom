# Checking an index

`loom:check` answers one question: **does the current index pass policy?**

Where [`loom:diff`](diff.md) reports *what changed* between two indexes, `loom:check` runs a set of policy rules against a single `index.json` and turns the result into an exit code. It's a CI gate: clean index, exit `0`; a rule found a violation, exit `1`; you fed it something it couldn't read, exit `2`. It runs no scanners and doesn't need your source tree — it reads the JSON and applies rules.

The two commands pair up. A typical job scans the branch, diffs against the baseline to *report* drift, and checks the fresh index to *block* on policy.

## Usage

```bash
php artisan loom:check {index?} {--baseline=} {--strict} {--skip=*} {--format=text}
```

```bash
# Check the index a scan just wrote
php artisan loom:scan
php artisan loom:check

# Check an explicit path, failing on any new unresolved dispatch vs a baseline
php artisan loom:check storage/loom/index.json --baseline=.loom/baseline.json
```

`index` is the path to the index JSON to check; it defaults to `storage/loom/index.json`. Every other input is a flag:

| Flag         | Effect                                                                                  |
| ------------ | --------------------------------------------------------------------------------------- |
| `--baseline` | Path to a previous index used for growth checks. Only `unresolved-dispatches` reads it. |
| `--strict`   | Raises the bar on growth rules: *any* unresolved dispatch is a failure, not just new ones. |
| `--skip`     | Skip a rule by its key. Repeatable: `--skip=orphan-events --skip=cyclic-dispatch`.      |
| `--format`   | How the result is rendered: `text` (default), `json`, or `markdown`.                    |

## The rules

Each rule has a stable key from the `RuleKey` enum. A rule either passes or contributes one or more violations; the run fails if any rule that ran produced a violation.

| Key                     | Checks                                                                  | Fails when                                                                                  |
| ----------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `schema`                | The index validates against `schema/loom-index.schema.json`.            | The document doesn't conform to the schema.                                                 |
| `orphan-listeners`      | Every listener handles at least one event.                              | A listener's `handles` array is empty.                                                      |
| `orphan-events`         | Every event is either dispatched or handled.                            | An event has both an empty `handled_by` and an empty `dispatched_from`.                      |
| `unresolved-dispatches` | No *new* unresolved dispatches relative to `--baseline`.                | A dispatch in `unresolved_dispatches` isn't present in the baseline (see semantics below).  |
| `cyclic-dispatch`       | No cyclic dispatch chains in the dispatch graph.                        | A dispatch chain loops back on itself (see [Cyclic dispatch detection](#cyclic-dispatch-detection)). |

### `--baseline` and `--strict` semantics

`unresolved-dispatches` is a growth rule: by default it polices only what a PR *adds*, not the debt it inherits. Its behavior depends entirely on the two flags:

- **Neither `--baseline` nor `--strict`** — the rule is a no-op. Growth can't be determined without a reference point, so it passes silently rather than guess.
- **`--baseline=PATH`** — the rule fails on any unresolved dispatch in the current index that isn't already in the baseline. Pre-existing unresolved dispatches are tolerated; new ones are not.
- **`--strict`** — the rule fails on *any* unresolved dispatch, baseline or not. Use this once you've driven the count to zero and want to keep it there.

`--strict` takes precedence: with `--strict` set, the baseline is irrelevant to this rule.

## Exit codes

| Code | Meaning                                                                                                |
| ---- | ------------------------------------------------------------------------------------------------------ |
| `0`  | Every rule that ran passed.                                                                             |
| `1`  | At least one rule found a violation. This is a policy failure — the gate is doing its job.             |
| `2`  | Invocation error: the index path isn't a file, the JSON is invalid, `--format` is unknown, or a `--skip` names a rule that doesn't exist. |

Exit `1` is the verdict; exit `2` means the command never got far enough to render one.

## Output formats

`--format` selects how the result is rendered. The verdict is identical across formats; only the presentation differs.

### text

The default. ANSI-colored output for a terminal. A clean run prints a single line; a failing run lists each rule with violations underneath it.

```text
orphan-listeners — Every listener handles at least one event
  ✗ Listener App\Listeners\LegacyAudit handles no events.
cyclic-dispatch — No cyclic event/job dispatch chains
  ✗ Cyclic dispatch: App\Events\OrderPlaced → App\Events\InventoryAdjusted → App\Events\OrderPlaced
2 violation(s) across 2 rule(s).
```

A clean run prints `All checks passed.` and exits `0`.

### json

Machine-readable. Every rule that *ran* appears — including skipped ones, so a consumer can see what was and wasn't enforced.

```json
{
  "passed": false,
  "violation_count": 2,
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
          "context": { "fqcn": "App\\Listeners\\LegacyAudit" }
        }
      ]
    },
    {
      "key": "orphan-events",
      "description": "Every event is dispatched or handled",
      "skipped": true,
      "violations": []
    },
    {
      "key": "unresolved-dispatches",
      "description": "No new unresolved dispatches (strict: none at all)",
      "skipped": false,
      "violations": []
    },
    {
      "key": "cyclic-dispatch",
      "description": "No cyclic event/job dispatch chains",
      "skipped": false,
      "violations": [
        {
          "message": "Cyclic dispatch: App\\Events\\OrderPlaced → App\\Events\\InventoryAdjusted → App\\Events\\OrderPlaced",
          "context": {
            "cycle": [
              "App\\Events\\OrderPlaced",
              "App\\Events\\InventoryAdjusted",
              "App\\Events\\OrderPlaced"
            ]
          }
        }
      ]
    }
  ]
}
```

### markdown

A `## loom:check` heading, then a `###` section per rule with violations, each carrying a bullet list — ready to paste into a PR comment.

```markdown
## loom:check

### orphan-listeners — Every listener handles at least one event
- Listener App\Listeners\LegacyAudit handles no events.

### cyclic-dispatch — No cyclic event/job dispatch chains
- Cyclic dispatch: App\Events\OrderPlaced → App\Events\InventoryAdjusted → App\Events\OrderPlaced
```

## Cyclic dispatch detection

`cyclic-dispatch` builds a directed graph from the index: a node per event and job, an edge wherever one primitive's handler dispatches another. Concretely, if a listener of `App\Events\OrderPlaced` dispatches `App\Events\InventoryAdjusted`, there's an edge `OrderPlaced → InventoryAdjusted`. Jobs participate the same way through their `dispatches`. A cycle is a path that returns to its start — `A → B → A`, or any longer chain, including a job that dispatches in a loop.

Each cycle is reported as the chain that closes it:

```text
Cyclic dispatch: App\Events\A → App\Events\B → App\Events\A
```

**Limitation.** The rule runs a deterministic depth-first search and reports one representative cycle per back-edge it finds. This surfaces every strongly-connected region of the graph — if there's a cycle anywhere, the rule fails and points at it — but it does **not** enumerate every elementary cycle within a tangled region. Fix the reported cycle, re-run, and any remaining cycle in that region surfaces next. Treat the output as "there is a cycle here," not "here is the complete list of cycles."

## In CI

The exit code is the whole interface. Scan, then check; a non-zero exit fails the step.

```yaml
- run: php artisan loom:scan
- name: Enforce architecture policy
  run: |
    php artisan loom:check storage/loom/index.json \
      --baseline=.loom/baseline.json \
      --format=markdown > check.md
    code=$?
    if [ "$code" -ne 0 ]; then
      gh pr comment "$PR" --body-file check.md   # surface the violations
    fi
    exit $code                                     # 1 (policy) or 2 (error) both fail the job
```

Pair it with [`loom:diff`](diff.md): diff *reports* what moved (and never fails the build on its own), check *enforces* what's allowed. Tune the gate with `--skip` to retire a rule you're not ready for, `--baseline` to ratchet unresolved dispatches down without blocking on inherited debt, and `--strict` once you've reached zero.

## See also

- [Diffing indexes](diff.md) — the sibling command: what changed between two indexes.
- [The index](the-index.md) — what `loom:check` reads.
- [Schema reference](schema.md) — the contract the `schema` rule validates against.
