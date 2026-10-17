# ADR 0006 — Benchmark suite: gate on deterministic counts, not wall time

**Status**: Accepted (2026-06-07)

Suite, profiles and commands: [`benchmarks/README.md`](https://github.com/lucasp1337/laravel-loom/blob/main/benchmarks/README.md).

## Context

A scanner can quietly start under- or over-counting, or scan cost can regress, with nothing noticing until a user does. Counts are deterministic for identical input; wall time is hardware-dependent and noisy across runners. A large wired app to scan must not mean committing thousands of fixture files.

## Decision

1. **Gate on counts.** `just bench --assert` fails only when section or per-scanner counts drift from the committed `benchmarks/baseline.json`. Counts are reproducible, so green on one machine is green on another, and a drift means a scanner's behaviour against a fixed app shape changed, which is exactly the regression worth catching. Wall time is measured and stored as informational `reference_ms`, and can be gated opt-in with `--time-threshold` for same-machine A/B runs.
2. **Generate the app, do not commit it.** `AppGenerator` writes a wired Laravel-shaped app to the system temp dir from three fixed profiles (`tiny`, `medium`, `large`). The same profile gives identical files and counts.

## Rejected

- Committing about 2000 real fixture files: repo bloat and a maintenance burden. A generator into a temp dir gives the same volume without the files.
- Gating CI on a wall-time percentage: hardware-dependent and noisy, so it would flake. Time stays informational and opt-in.

## Consequences

- The gate is stable across machines and CI, and the runner uses `DefaultScanners`, so it benchmarks what `loom:scan` runs.
- Time regressions are not caught automatically.
- The baseline is regenerated (`just bench-baseline`) and reviewed whenever counts legitimately move.
