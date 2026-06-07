# ADR 0006 — Benchmark suite: gate on deterministic counts, not wall time

**Status**: Accepted (2026-06-07)

**Reference**: [`benchmarks/README.md`](../../benchmarks/README.md) for the suite,
its profiles, and the commands. This ADR captures only the load-bearing decision.

## Context

As coverage expands, a scanner could quietly start under- or over-counting, or a
change could regress scan performance — and nothing would notice until a user did.
We want a reproducible benchmark that reports scan cost and can fail CI on
regression.

Two things could be asserted on: the entry **counts** a scan produces, and the
**wall time** it takes. Counts are deterministic given identical input. Wall time
is hardware-dependent and noisy across machines and CI runners.

We also needed a sizeable, *wired* app to scan. Committing 2000 real fixture
files would bloat the repo and become a maintenance burden.

## Decision

### 1. Assertions gate on deterministic counts

`composer bench:assert` fails only when section or per-scanner counts drift from
the committed `benchmarks/baseline.json`. Counts are reproducible, so this is a
stable gate: green on one machine is green on another. A drift means a scanner's
behaviour against a fixed app shape changed — exactly the regression worth
catching.

Wall time is still measured and reported, and stored as `reference_ms` in the
baseline (informational). It can be gated opt-in via `--time-threshold` for
same-machine A/B comparison, but is never a hard CI gate.

### 2. A deterministic generator into a temp dir, not committed fixtures

`AppGenerator` materialises a wired Laravel-shaped app into the system temp dir
(outside the repo) from three fixed-shape profiles (`tiny` / `medium` / `large`),
scaling one base distribution by a factor. Same profile → identical files →
identical counts. Nothing generated is linted or committed.

## Consequences

**Good:**

- The count gate is stable across machines and CI, so it can run in CI without
  flaking.
- No large fixture tree in the repo; the app shape lives in a small generator.
- The runner consumes the shared `DefaultScanners` set, so it always benchmarks
  exactly what `loom:scan` runs.

**Costs:**

- Wall-time regressions are not caught automatically — a contributor must look at
  the reported timings, or run `--time-threshold` locally.
- The baseline must be regenerated (`composer bench:baseline`) and reviewed
  whenever a change legitimately moves the counts.

## Alternatives considered

1. **Commit ~2000 real fixture files.** Rejected: repo bloat and a maintenance
   burden. A deterministic generator into a temp dir gives the same volume without
   the files.
2. **Gate on wall-time percentage in CI.** Rejected: hardware-dependent and noisy,
   so it would be flaky. Counts are the deterministic gate; time stays
   informational and opt-in.
