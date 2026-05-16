---
description: Prepare a release — bump version, verify changelog, tag check, draft notes
argument-hint: <version> (e.g. 0.1.0, 0.2.0, 1.0.0)
---

Prepare release `$ARGUMENTS` for Laravel Atlas.

## Pre-flight

1. Confirm `$ARGUMENTS` is valid semver
2. Confirm the version bump is appropriate by consulting `schema-guardian` if any schema changes shipped since the last tag
3. Check the working tree is clean (`git status`)
4. Confirm you are on `main` and up to date with origin
5. Run `/run-checks` — abort if any check fails

## Steps

### 1. Verify CHANGELOG

Read `CHANGELOG.md`. Confirm there is an `[Unreleased]` section with content. If empty, abort — no changes to release.

### 2. Promote [Unreleased] to versioned entry

Move the `[Unreleased]` content under a new `## [$ARGUMENTS] - YYYY-MM-DD` heading. Add a fresh empty `## [Unreleased]` at the top.

### 3. Update version references

- `composer.json` — bump `version` if present (Composer normally takes version from tags; only set if explicitly versioned)
- `src/Atlas.php` (or wherever `VERSION` const lives) — update
- `README.md` — if it shows the version anywhere, update

### 4. Confirm tag does not exist

```bash
git tag --list "v$ARGUMENTS"
```

If output is non-empty, abort — tag already exists.

### 5. Draft release notes

Produce a `release-notes-$ARGUMENTS.md` (do not commit) with:

- Headline (one sentence: what this release is about)
- Highlights (3-5 bullets pulled from CHANGELOG, plain English not changelog speak)
- Breaking changes (if any) with migration guidance
- Schema version note (mention `atlas_version` if it changed)
- Acknowledgments

This is for the human to paste into the GitHub release UI. Do not push or create the release yourself.

### 6. Show diff

```bash
git diff
```

Report what is about to be committed. Pause for the human to review.

### 7. Stop here

Do not commit. Do not tag. Do not push. The human runs:

```bash
git add -A
git commit -m "chore: release v$ARGUMENTS"
git tag -a v$ARGUMENTS -m "Release v$ARGUMENTS"
git push origin main --follow-tags
```

…after reviewing your output.

## Abort conditions

- Dirty working tree
- `[Unreleased]` empty
- Tag already exists
- `/run-checks` fails
- Schema changes without appropriate version bump (consult `schema-guardian`)

When you abort, report the reason clearly.
