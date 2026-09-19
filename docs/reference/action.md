# GitHub Action reference

Inputs, outputs, permissions and failure behaviour of the Loom composite action. For a walkthrough, see [Gate your CI](../guides/gate-your-ci.md).

The action scans your branch, runs `loom:check`, diffs against the pull request's base branch and posts one sticky comment. It uses the repository itself as the action, so you reference `lucasp1337/laravel-loom`, not a subpath.

!!! warning "Loom must already be installed in your app"
    The action runs `composer install` in your app and then `php artisan loom:scan`. If your app doesn't require `lucasp1337/laravel-loom` (as a dev dependency), the scan fails with "command not defined". The action doesn't bundle a Laravel app.

!!! note "No release tag includes the action yet"
    `v0.1.0` and `v0.2.0` predate it, so the only ref that works today is a branch such as `main`. Pin to a tag once a release ships it.

## Minimal workflow

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
```

## Inputs

| Input | Default | Effect | Consequence |
| --- | --- | --- | --- |
| `php-version` | `8.3` | PHP version installed, with `dom`, `mbstring` and `xml`. | Must satisfy your app's `composer.json`, or `composer install` fails. |
| `laravel-version` | empty | Laravel version to report. Empty reads it from `composer.lock`. | Informational. It doesn't change what gets installed. |
| `strict` | `false` | Runs `loom:check --strict`. | Any unresolved dispatch fails the build. Left off, unresolved dispatches are never checked. |
| `comment-on-pr` | `true` | Posts or updates a sticky PR comment with the check result and diff. | Needs `pull-requests: write`. Does nothing outside `pull_request` events. |
| `fail-on-diff` | `false` | Fails the build when the diff reports changes. | Every PR that touches architecture goes red. Use it to force review, not as a policy gate. |
| `working-directory` | `.` | Directory holding `artisan` and `composer.json`. | The base-branch diff assumes the app is at the repository root, so in a subdirectory it is skipped. |

## Outputs

| Output | Value | Watch for |
| --- | --- | --- |
| `index-path` | Absolute path to the `index.json` the scan wrote. | Point your own later steps at it, for example `loom:check --baseline`. |
| `unresolved-count` | Number of unresolved-dispatch violations from `loom:check`. | Always `0` unless `strict` is `true`, because the action never passes a baseline. |
| `diff-summary` | One line about the diff. | See below. Empty on events other than `pull_request`. |

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

Without `strict`, the check runs `schema`, `orphan-listeners`, `orphan-events` and `cyclic-dispatch`. With it, `unresolved-dispatches` runs too. Rule details are in [Check rules and output formats](check-rules-and-formats.md#rules).

!!! warning "A missing diff is not a clean diff"
    The action builds the base index by fetching the base branch into a temporary worktree, installing dependencies and scanning it. If any of that fails, the diff is skipped and the build passes. That includes the first PR that adds Loom, since the base doesn't have it yet.

## Permissions

| Permission | Why |
| --- | --- |
| `contents: read` | Checkout and the base-branch fetch. |
| `pull-requests: write` | Posting and updating the comment. |

!!! warning "Fork PRs can't comment"
    On pull requests from forks, `GITHUB_TOKEN` is read-only, so the comment step fails and takes the job with it. Set `comment-on-pr: "false"` for workflows that run on fork PRs, and read the result from the job log.

## What the comment contains

The comment starts with `# Laravel Loom`, the diff summary line, then the markdown output of `loom:check` (or `All checks passed.`). When the diff found changes, the diff sits below it in a collapsed "Architectural diff" block. The comment is edited in place on later pushes. Formats are shown in [Check rules and output formats](check-rules-and-formats.md#markdown).
