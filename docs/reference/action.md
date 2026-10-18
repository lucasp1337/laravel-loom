# GitHub Action reference

The Loom composite action scans your branch, runs `loom:check`, diffs against the pull request's base branch and posts one sticky comment. The walkthrough is [Gate your CI](../guides/gate-your-ci.md).

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
```

Your app must require `lucasp1337/laravel-loom` as a dev dependency: the action runs `composer install`, then `php artisan loom:scan`.

## Inputs

| Input | Default | Effect | Consequence |
| --- | --- | --- | --- |
| `php-version` | `8.3` | PHP version installed, with `dom`, `mbstring` and `xml`. | Must satisfy your app's `composer.json`. |
| `laravel-version` | empty | Laravel version to report. Empty reads it from `composer.lock`. | Informational only. |
| `strict` | `false` | Runs `loom:check --strict`. | Left off, unresolved dispatches are never checked. |
| `comment-on-pr` | `true` | Posts or updates a sticky PR comment with the check result and diff. | Needs `pull-requests: write`. Does nothing outside `pull_request` events. |
| `fail-on-diff` | `false` | Fails the build when the diff reports changes. | Every PR that touches architecture goes red. |
| `working-directory` | `.` | Directory holding `artisan` and `composer.json`. | In a subdirectory the base-branch diff is skipped. |

## Outputs

| Output | Value | Watch for |
| --- | --- | --- |
| `index-path` | Absolute path to the `index.json` the scan wrote. | Use it in later steps, such as `loom:check --baseline`. |
| `unresolved-count` | Number of unresolved-dispatch violations from `loom:check`. | Always `0` unless `strict` is `true`: the action never passes a baseline. |
| `diff-summary` | One line about the diff. | Empty outside `pull_request` events. |

`diff-summary` is one of:

| Value | Meaning |
| --- | --- |
| `No architectural changes vs <base>.` | The diff exited `0`. |
| `Architectural changes detected vs <base>.` | The diff exited `1`. The full diff is in the PR comment. |
| `loom:diff failed (exit N).` | Input error. The build isn't failed for it. |
| `Diff skipped (no base index could be built).` | The base branch couldn't be scanned. |

## What fails the build

| Condition | Build |
| --- | --- |
| `loom:check` exits `1` (policy violation) | Fails with exit `1`. |
| `loom:check` exits `2` (couldn't run) | Fails with exit `2`. |
| Diff finds changes, `fail-on-diff` is `false` | Passes. |
| Diff finds changes, `fail-on-diff` is `true` | Fails with exit `1`. |
| Diff skipped or errored | Passes, whatever `fail-on-diff` says. |

Without `strict`, `unresolved-dispatches` checks nothing; the other [rules](check-rules-and-formats.md#rules) always run.

!!! warning "A missing diff is not a clean diff"
    The base index is built by installing and scanning the base branch in a temporary worktree. If that fails, as on the first PR that adds Loom, the diff is skipped and the build passes.

## Permissions

| Permission | Why |
| --- | --- |
| `contents: read` | Checkout and the base-branch fetch. |
| `pull-requests: write` | Posting and updating the comment. |

!!! warning "Fork PRs can't comment"
    On fork PRs `GITHUB_TOKEN` is read-only, so the comment step fails the job. Set `comment-on-pr: "false"` there and read the job log.

## What the comment contains

The comment holds the diff summary, the [markdown output](check-rules-and-formats.md#markdown) of `loom:check`, and any diff in a collapsed block. Later pushes edit it in place.
