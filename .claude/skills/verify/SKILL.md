---
name: verify
description: Run every check before committing or opening a PR (Pint, PHPStan, Rector, Pest, benchmark gate, generated docs, strict docs build). Use after any change to src/, tests/, schema/, config/ or docs/.
---

# Verify

Run from the repo root. Just + Docker only: never run php or composer directly. A fresh worktree needs `just build` and `just install` first.

```
just verify
```

It runs Pint, PHPStan, Rector (dry run), Pest, the benchmark count gate, the generated-docs check and the strict docs build, and stops at the first failure.

- Pint, Rector or stale generated docs: `just fix` (rector, then pint, then docs-generate), review the diff, re-run `just verify`.
- Focused run while iterating: `just test-filter X`.
- Counts legitimately moved: `just bench-baseline`, review the diff.
- Never dispatch GitHub CI to verify.
