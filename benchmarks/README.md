# Loom benchmark suite

A reproducible measurement of what a Loom scan costs, so coverage expansion can't
silently regress v1 performance. It generates a Laravel-shaped app of a known
size, runs the production scanner set against it, and reports wall time, peak
memory, and a per-scanner entry-count breakdown.

The suite gates CI on **deterministic counts**, not wall-clock time. Counts are
reproducible and catch real regressions — a scanner that starts under- or
over-counting the fixtures. Wall time is measured and reported, but only gated
opt-in for same-machine comparison. See
[ADR 0006](../docs/adr/0006-benchmark-suite.md) for the rationale.

## What it measures

Two passes per profile, both running the shared
`Lucasp\Loom\Scanners\DefaultScanners` set — the same nine scanners `loom:scan`
uses:

- **Headline:** one full `IndexBuilder::build()`, timed with `hrtime` and peak
  memory via `memory_reset_peak_usage()` / `memory_get_peak_usage()`.
- **Breakdown:** each scanner's `scan()` run in isolation, for per-scanner time
  and the entry counts it emits.

Reported columns: profile, files, build time, peak memory, total entries; then a
per-scanner table (scanner, time, entries) per profile.

## The three profiles

Each profile scales one base category distribution by a fixed factor, so the app
*shape* is constant and only volume grows:

| Profile  | Files  | Roughly                          |
|----------|--------|----------------------------------|
| `tiny`   | ~19    | a fresh `laravel new`            |
| `medium` | ~201   | a mid-size application           |
| `large`  | ~2035  | a large application              |

Same profile always produces identical files, hence identical counts — that's
what makes the count assertion deterministic.

## How the generator works

`AppGenerator` deterministically materialises a *wired* Laravel-shaped app into a
directory. By default this is the system temp dir (`sys_get_temp_dir()/loom-bench`),
**outside the repo**, so generated files are never linted or committed.

Wired means every scanner and the cross-linker do real work: services dispatch
generated events and jobs, models register observers via `#[ObservedBy]`, routes
target controllers, and the Kernel schedules commands and jobs. The same profile
generates byte-identical files every run.

## Commands

`composer` scripts run on the host; `just bench [...]` runs the same thing in
Docker.

| Command                                   | What it does                                            |
|-------------------------------------------|---------------------------------------------------------|
| `composer bench`                          | Run all sizes, print the table.                         |
| `php benchmarks/bench.php --size=medium`  | Run one size only.                                      |
| `php benchmarks/bench.php --json`         | Machine-readable output.                                |
| `composer bench:baseline`                 | (Re)write `benchmarks/baseline.json`.                   |
| `composer bench:assert`                   | Fail (exit 1) if section/scanner counts drift.          |
| `just bench [...]`                        | Any of the above, in Docker.                            |

Other flags:

- `--time-threshold=0.5` — with `--assert`, *additionally* fail if build wall
  time is more than 50% over the baseline reference. Same-machine only.
- `--keep` — reuse already-generated apps instead of regenerating.
- `--out-dir=PATH` — generate into `PATH` instead of the temp dir.

## The baseline

`benchmarks/baseline.json` is the committed assertion reference. For each profile
it records the file count, per-section counts, per-scanner counts, and a
`reference_ms` wall time (informational only).

Update it deliberately when a scanner change legitimately moves the counts:

```bash
composer bench:baseline   # re-run all sizes and overwrite baseline.json
```

Then review the diff — the count changes should match the behaviour change you
shipped. A surprising delta is the signal the assertion exists to catch. Commit
the updated baseline alongside the change.

## Using `bench:assert` in CI

`composer bench:assert` regenerates the apps, scans them, and compares the live
counts against `baseline.json`. Any drift in a section or per-scanner count is a
non-zero exit. Because the generator is deterministic, this is stable across
machines — a green run on one box is green on another.

Wall time is deliberately **not** a hard CI gate: it's hardware-dependent and
noisy, so committing a time threshold would be flaky. To compare timing on a
single machine (e.g. before/after a refactor), add `--time-threshold=N` locally.
