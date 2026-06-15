# GitHub Action

The repository ships a composite action that wires `loom:scan`, [`loom:diff`](diff.md), and [`loom:check`](check.md) into a single PR gate: it scans the branch, checks the fresh index against policy, diffs it against the base ref, and posts the result as a sticky PR comment.

## Invocation model

The action runs **inside a consumer's Laravel app** — a repository that has `laravel-loom` installed as a dev dependency and therefore provides `artisan`. It does not bundle a Laravel app of its own. Point `working-directory` at the app root (the directory with `artisan` and `composer.json`); it defaults to the repository root.

## Usage

A minimal `on: pull_request` workflow:

```yaml
name: Loom
on: pull_request

permissions:
  contents: read
  pull-requests: write   # required for comment-on-pr

jobs:
  loom:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: lucasp1337/laravel-loom@v1
        with:
          strict: "true"
          fail-on-diff: "false"
```

The action lives at the repository root, so consumers reference the repo itself (`lucasp1337/laravel-loom@v1`), not a subpath.

## Inputs

| Input               | Default | Effect                                                                                  |
| ------------------- | ------- | --------------------------------------------------------------------------------------- |
| `php-version`       | `8.3`   | PHP version to set up (extensions `dom`, `mbstring`, `xml`).                             |
| `laravel-version`   | `""`    | Laravel version to report. Empty auto-detects from `composer.lock`.                     |
| `strict`            | `false` | Pass `--strict` to `loom:check` — fail on *any* unresolved dispatch, not just new ones. |
| `comment-on-pr`     | `true`  | Post/update a sticky PR comment with the check + diff summary. No-ops outside a PR.     |
| `fail-on-diff`      | `false` | Fail the build when `loom:diff` reports changes vs the base ref.                         |
| `working-directory` | `.`     | Path to the Laravel app root.                                                           |

## Outputs

| Output             | Value                                                                       |
| ------------------ | --------------------------------------------------------------------------- |
| `index-path`       | Absolute path to the `index.json` written by `loom:scan`.                   |
| `unresolved-count` | Count of unresolved-dispatch violations from `loom:check`.                  |
| `diff-summary`     | One-line summary of the diff vs the base ref (empty when no base diff ran). |

## What fails the build

`loom:check` is the gate: a policy violation (exit `1`) or an invocation error (exit `2`) always fails the build. `loom:diff` is informational by default and only fails the build when `fail-on-diff` is `true` (and then only on exit `1`, "changes found"). This mirrors the exit-code split the two commands already follow — see [check.md](check.md) and [diff.md](diff.md).

## Baseline trade-off

The diff needs a *base index* to compare the branch against. The action builds one automatically by fetching the PR base ref into a detached `git worktree`, installing dependencies there, and running `loom:scan` against that checkout.

This needs no committed baseline file, but it is **best-effort**: if the base ref can't be fetched (shallow clone with no base history), or dependencies won't install at the base commit, the diff is skipped — `diff-summary` reports that and the build is not failed for it. The check gate is unaffected; it only ever reads the branch's own index. If you want a deterministic diff, commit a baseline index and run `loom:diff` yourself in a dedicated step instead.

## Auto-detect note

When `laravel-version` is left empty, the action reads `laravel/framework`'s version from `composer.lock` (stripping the leading `v`). It is informational only — exported context for the run, not a constraint on what gets installed.
