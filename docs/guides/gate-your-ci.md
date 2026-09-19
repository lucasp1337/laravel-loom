# Gate your CI on architecture

By the end of this page, a pull request that adds a dead event, a dispatch loop or a new dispatch Loom can't resolve fails your build, and reviewers see what changed in a PR comment.

You need Loom installed as a dev dependency (`composer require lucasp1337/laravel-loom --dev`) and a repository on GitHub. The examples use an order and checkout app.

## Run the gate locally first

Scan the branch, then check the index the scan wrote.

```bash
php artisan loom:scan
php artisan loom:check
```

Suppose a branch adds a stock-reservation listener that dispatches `InventoryAdjusted`, and a recount listener that handles it and dispatches `OrderPlaced` again. It also adds an unused `OrderCancelled` event, an untyped `LegacyAudit` listener, and a dispatch built from a variable. The check fails:

```text
orphan-listeners — Every listener handles at least one event
  ✗ Listener App\Listeners\LegacyAudit handles no events.
orphan-events — Every event is dispatched or handled
  ✗ Event App\Events\OrderCancelled is never dispatched and never handled.
cyclic-dispatch — No cyclic event/job dispatch chains
  ✗ Cyclic dispatch: App\Events\InventoryAdjusted → App\Events\OrderPlaced → App\Events\InventoryAdjusted
3 violation(s) across 3 rule(s).
```

The dynamic dispatch isn't in that list: without a baseline, `unresolved-dispatches` is silent (next section). The command exited `1`, and that exit code is the gate: a CI step that runs `loom:check` fails on any non-zero code. Each rule and what to do about it is in [Check rules and output formats](../reference/check-rules-and-formats.md#rules).

!!! warning "Exit 2 is not a pass"
    `loom:check` exits `2` when it couldn't run: missing file, invalid JSON, unknown `--format`, or a `--skip` naming a rule that doesn't exist. A mistyped `--skip=orphan-event` exits `2` with the list of valid keys. A wrapper that only tests for `1` treats that as green.

## Stop new unresolved dispatches without fixing the old ones

A dispatch like `event(new $eventClass())` can't be traced to a class, so Loom lists it under `unresolved_dispatches` instead of guessing. Your existing app probably has some, and you don't want to fix them all before turning the gate on.

`loom:check` on its own does not police them, even though the rule is listed in its output:

```bash
php artisan loom:check
```

```text
All checks passed.
```

That was a scan with one unresolved dispatch in it. Give the rule a reference point, a baseline index from a known-good commit:

```bash
mkdir -p .loom
cp storage/loom/index.json .loom/baseline.json
git add .loom/baseline.json
```

Now the rule fails only on dispatches the baseline doesn't have. Here the baseline predates the dynamic `event(new $eventClass())` line:

```bash
php artisan loom:check --baseline=.loom/baseline.json
```

```text
unresolved-dispatches — No new unresolved dispatches (strict: none at all)
  ✗ Unresolved dispatch (dynamic_class_name) at app/Services/Checkout.php:14: event(new $eventClass())
1 violation(s) across 1 rule(s).
```

!!! warning "Baselines match on file and line"
    An unresolved dispatch counts as inherited only if its file, line and expression are all unchanged. Add a line above an old one and it counts as new, so refresh the baseline whenever you accept new debt or the file shifts.

Once the count is zero, drop the baseline and use `--strict`, which fails on any unresolved dispatch:

```bash
php artisan loom:check --strict
```

## See what changed

The check says whether the index is allowed. `loom:diff` says what moved between two indexes, which is what a reviewer wants to see.

```bash
php artisan loom:diff .loom/baseline.json storage/loom/index.json
```

```text
unresolved_dispatches
  + {"file":"app/Services/Checkout.php","line":14,"expression":"event(new $eventClass())","reason":"dynamic_class_name"}
```

One `+` line means one new unresolved dispatch, and nothing else changed. The command exited `1` because changes exist. That is not an error: `loom:diff` follows `git diff --exit-code`, so `0` means no changes, `1` means changes, and `2` means it couldn't read an input.

!!! warning "A bare loom:diff step fails the job"
    Any step that runs `loom:diff` without handling exit `1` goes red the moment the architecture changes. The Action below handles this for you. In a hand-written step, capture the code and only fail on `2`. Formats and the exact output are in [Check rules and output formats](../reference/check-rules-and-formats.md#diff-output).

## Run both on every pull request

The repository ships a GitHub Action that scans the branch, runs `loom:check`, diffs against the base branch and posts one sticky PR comment. Add `.github/workflows/loom.yml`:

```yaml
name: Loom
on: pull_request

permissions:
  contents: read
  pull-requests: write

jobs:
  loom:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: lucasp1337/laravel-loom@main
        with:
          strict: "true"
```

`pull-requests: write` lets the action post the comment. `strict: "true"` fails the build on any unresolved dispatch. Orphans, cycles and schema errors always fail it.

!!! note "No release tag includes the action yet"
    Neither `v0.1.0` nor `v0.2.0` contains it, so the example points at `main`. Once a release ships it, pin to that tag instead of a moving branch.

The job is green when the check exits `0`. It fails with the same exit code the check returned, and the PR comment lists the violations from the [markdown format](../reference/check-rules-and-formats.md#markdown).

!!! warning "The Action can't ratchet"
    It never passes `--baseline`. With `strict` off, `unresolved-dispatches` checks nothing, and the `unresolved-count` output is always `0`. Choose `strict: "true"` from day one, or run `loom:check --baseline` in your own step.

!!! warning "The diff is best-effort"
    The Action builds the base index by installing and scanning the base branch. If that fails, for instance because Loom isn't installed on the base yet, the diff is skipped and the build still passes. A missing diff comment doesn't mean nothing changed. Inputs, outputs and the fork-PR limit are in [Action reference](../reference/action.md).

To fail the build on any architectural change, not just policy violations, add `fail-on-diff: "true"`.

## Inputs that make it fail

Use these to confirm the gate is wired up before you rely on it.

| Change on a test branch | Gate result |
| --- | --- |
| Add an event class nothing dispatches or handles | `orphan-events` fails, exit `1` |
| Add a listener whose `handle()` has no typed event | `orphan-listeners` fails, exit `1` |
| Make two listeners dispatch each other's events | `cyclic-dispatch` fails, exit `1` |
| Add `event(new $class())` with `strict` on | `unresolved-dispatches` fails, exit `1` |
| Run `loom:check --skip=orphan-event` | Exit `2`, nothing was checked |

You're done when the first row turns your PR red and reverting it turns it green.
