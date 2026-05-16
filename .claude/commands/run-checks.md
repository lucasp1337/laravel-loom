---
description: Run the full quality check sequence (PHPStan, Pint, Pest)
---

Run all quality checks for Laravel Atlas. Halt on first failure and report clearly.

## Sequence

Execute in order. Stop immediately on any non-zero exit.

```bash
./vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

If this fails:
- Read the PHPStan output
- Invoke `quality-inspector` to interpret and fix
- Do not proceed to Pint until PHPStan is clean

```bash
./vendor/bin/pint --test
```

If this fails:
- Run `./vendor/bin/pint` (without `--test`) to auto-fix
- Re-run `--test` to confirm clean
- Do not proceed to Pest until Pint is clean

```bash
./vendor/bin/pest
```

If this fails:
- Read the failing test output
- Invoke `test-engineer` to diagnose
- Report the failure with file:line of the failing assertion

## Reporting

On success: report a one-line summary like `All checks passed: PHPStan (clean), Pint (clean), Pest (N tests, M assertions).`

On failure: report which check failed, the relevant output excerpt, and which agent was invoked to address it. Do not auto-commit.

## Do not

- Skip a check because "it usually passes"
- Auto-fix Pint without showing what changed
- Mark complete with any check failing
- Continue past a failing check
