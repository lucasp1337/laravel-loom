# Gate your CI on architecture

By the end of this page a pull request that adds a dead event, a dispatch loop or a new dispatch Loom can't resolve fails your build, and reviewers see what changed in a PR comment. You need Loom as a dev dependency (`composer require lucasp1337/laravel-loom --dev`) and a repository on GitHub.

## Run the gate locally first

```bash
php artisan loom:scan
php artisan loom:check
```

On a branch that adds an unused `OrderCancelled` event, an untyped `LegacyAudit` listener and two listeners that dispatch each other's events, the check fails:

```text
orphan-listeners — Every listener handles at least one event
  ✗ Listener App\Listeners\LegacyAudit handles no events.
orphan-events — Every event is dispatched or handled
  ✗ Event App\Events\OrderCancelled is never dispatched and never handled.
cyclic-dispatch — No cyclic event/job dispatch chains
  ✗ Cyclic dispatch: App\Events\InventoryAdjusted → App\Events\OrderPlaced → App\Events\InventoryAdjusted
3 violation(s) across 3 rule(s).
```

The command exits `1`, and that exit code is the gate. Each rule is explained in [Check rules and output formats](../reference/check-rules-and-formats.md#rules).

!!! warning "Exit 2 is not a pass"
    `loom:check` exits `2` when it couldn't run: missing file, invalid JSON, unknown `--format`, or a `--skip` naming a rule that doesn't exist. A wrapper that only tests for `1` treats that as green.

## Stop new unresolved dispatches without fixing the old ones

A dispatch like `event(new $eventClass())` can't be traced to a class, so it is listed under `unresolved_dispatches`. Plain `loom:check` does not police them, and passes. Give the rule a baseline index from a known-good commit:

```bash
mkdir -p .loom && cp storage/loom/index.json .loom/baseline.json
git add .loom/baseline.json
php artisan loom:check --baseline=.loom/baseline.json
```

```text
unresolved-dispatches — No new unresolved dispatches (strict: none at all)
  ✗ Unresolved dispatch (dynamic_class_name) at app/Services/Checkout.php:14: event(new $eventClass())
```

!!! warning "Baselines match on file and line"
    A dispatch counts as inherited only if its file, line and expression are unchanged. Add a line above an old one and it counts as new, so refresh the baseline when you accept new debt.

Once the count is zero, drop the baseline and use `--strict`, which fails on any unresolved dispatch.

## See what changed

`loom:diff` says what moved between two indexes:

```bash
php artisan loom:diff .loom/baseline.json storage/loom/index.json
```

```text
unresolved_dispatches
  + {"file":"app/Services/Checkout.php","line":14,"expression":"event(new $eventClass())","reason":"dynamic_class_name"}
```

It follows `git diff --exit-code`: `0` no changes, `1` changes (not an error), `2` an unreadable input.

!!! warning "A bare loom:diff step fails the job"
    A step that doesn't handle exit `1` goes red whenever the architecture changes. In a hand-written step, fail only on `2`. Formats are in [Check rules and output formats](../reference/check-rules-and-formats.md#diff-output).

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
      - uses: lucasp1337/laravel-loom@v0.4.0
        with:
          strict: "true"
```

`strict: "true"` fails the build on any unresolved dispatch; orphans, cycles and schema errors always fail it. Add `fail-on-diff: "true"` to fail on any architectural change.

!!! warning "The Action can't ratchet"
    It never passes `--baseline`, so with `strict` off `unresolved-dispatches` checks nothing and `unresolved-count` is always `0`. Use `strict: "true"` from day one, or run `loom:check --baseline` in your own step.

!!! warning "The diff is best-effort"
    The base index comes from installing and scanning the base branch. If that fails the diff is skipped and the build still passes, so a missing diff comment doesn't mean nothing changed. Inputs, outputs and the fork-PR limit are in [Action reference](../reference/action.md).

## Confirm it is wired up

Add an event nothing dispatches or handles on a test branch: `orphan-events` should turn the PR red, and reverting should turn it green. Running `loom:check --skip=orphan-event` (a typo) exits `2`.
