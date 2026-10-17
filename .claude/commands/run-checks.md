---
description: Run the full quality gate (`just verify`)
---

Run the full gate for Laravel Loom. Just + Docker only: never run php or composer directly.

```bash
just verify
```

It stops at the first failure: Pint, PHPStan, Rector (dry run), Pest, benchmark count gate, generated-docs check, strict docs build.

On failure:
- Pint, Rector or stale generated docs: run `just fix`, show what changed, re-run `just verify`.
- PHPStan: read the output and invoke `quality-inspector`.
- Pest: read the failing output, invoke `test-engineer`, and report file:line of the failing assertion. Use `just test-filter X` to iterate.

On success: report a one-line summary.

Do not auto-commit, skip a check, or dispatch GitHub CI.
