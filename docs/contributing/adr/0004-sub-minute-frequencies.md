# ADR 0004 — Sub-minute schedule frequencies: structured `frequency` object

**Status**: Accepted (2026-06-07)

Builds on [ADR 0002](0002-schedule-scanner.md), which made `cron` a canonical five-field string. The current rules are in the `ScheduleScanner` docblock.

## Context

Laravel has seven sub-minute helpers: `everySecond`, `everyTwoSeconds`, `everyFiveSeconds`, `everyTenSeconds`, `everyFifteenSeconds`, `everyTwentySeconds` and `everyThirtySeconds`. A five-field cron has a one-minute floor, so they fell through to `cron: null`, indistinguishable from an unrecognised helper.

## Decision

Add `frequency: { "unit": "seconds", "every": 10 }` to every schedule entry.

- The seven helpers set `frequency` and leave `cron` `null`; no cron is fabricated.
- Every other entry has `frequency: null`.
- `unit` is the `FrequencyUnit` enum, currently only `seconds`, so new units extend the field instead of overloading `cron`.
- Last wins in both directions, as at runtime: a sub-minute helper after a cron helper clears `cron`, and a cron helper after a sub-minute helper clears `frequency`. At most one is non-null.

## Rejected

- A six-field cron with leading seconds: no standard parser reads it, and ADR 0002 chose a string because consumers already speak five fields.
- `cron: null` plus an `everySeconds(10)` string in `constraints[]`: constraints are evaluated in addition to the tick, and a frequency is not one.

## Consequences

- "What runs every ten seconds" is a field read.
- `cron` keeps its five-field invariant.
- One more nullable field and a mutual-exclusion rule for consumers; the helper table is hand-maintained.
