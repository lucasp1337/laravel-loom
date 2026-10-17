---
name: verify
description: Run every local check before committing or opening a PR (tests, PHPStan, Pint, benchmark gate, generated docs, Rector). Use after any change to src/, tests/, schema/, config/ or docs/.
---

# Verify

Run from the repo root. The host `php` is 8.5 without `ext-xml`; use `php8.3`. A fresh worktree needs `php8.3 $(which composer) install` first.

```
php8.3 vendor/bin/pest </dev/null
php8.3 vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php8.3 vendor/bin/pint --test
php8.3 benchmarks/bench.php --assert
php8.3 tools/generate-reference.php --check
php8.3 vendor/bin/rector process --dry-run --ansi
```

- Pint failures: run `php8.3 vendor/bin/pint`, then re-run the test.
- Rector (`composer rector` runs the same dry run) failures: `php8.3 vendor/bin/rector process --ansi`, then Pint.
- Stale generated docs: `php8.3 tools/generate-reference.php`, review the diff.
- When docs changed, also run `just docs-check`, and `just docs-build` for the strict site build (both use Docker).
- Never dispatch GitHub CI to verify; run the checks locally.
