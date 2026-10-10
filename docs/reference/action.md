# GitHub Action reference

Inputs, outputs, permissions and failure behaviour of the Loom composite action. For a walkthrough, see [Gate your CI](../guides/gate-your-ci.md).

The action scans your branch, runs `loom:check`, diffs against the pull request's base branch and posts one sticky comment. Reference `lucasp1337/laravel-loom`, not a subpath.

!!! warning "Loom must already be installed in your app"
    The action runs `composer install` and then `php artisan loom:scan`. If your app doesn't require `lucasp1337/laravel-loom` as a dev dependency, the scan fails with "command not defined".

The workflow is in [Gate your CI](../guides/gate-your-ci.md#run-both-on-every-pull-request).

## Inputs

| Input | Default | Effect |
| --- | --- | --- |
| `php-version` | `8.3` | PHP installed, with `dom`, `mbstring` and `xml`. Must satisfy your `composer.json`. |
| `laravel-version` | empty | Laravel version to report; empty reads `composer.lock`. Informational. |
| `strict` | `false` | Runs `loom:check --strict`: any unresolved dispatch fails. Off, they are never checked. |
| `comment-on-pr` | `true` | Posts or updates a sticky PR comment. Needs `pull-requests: write`; only on `pull_request` events. |
| `fail-on-diff` | `false` | Fails the build when the diff reports changes, so every architectural PR goes red. |
| `working-directory` | `.` | Directory holding `artisan` and `composer.json`. The base-branch diff assumes the repository root, so in a subdirectory it is skipped. |

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
    The base index comes from fetching the base branch into a temporary worktree, installing and scanning it. If that fails, including on the first PR that adds Loom, the diff is skipped and the build passes.

## Permissions and comment

`contents: read` covers checkout and the base-branch fetch; `pull-requests: write` covers the comment. On fork PRs `GITHUB_TOKEN` is read-only, so the comment step fails and takes the job with it: set `comment-on-pr: "false"` for workflows that run on forks and read the job log.

The comment starts with `# Laravel Loom`, the diff summary, then the markdown output of `loom:check` (or `All checks passed.`), with the diff in a collapsed block when it found changes. Later pushes edit it in place. Formats are in [Check rules and output formats](check-rules-and-formats.md#check-output).
