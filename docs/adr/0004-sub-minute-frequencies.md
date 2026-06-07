# ADR 0004 — Sub-minute schedule frequencies: structured `frequency` object

**Status**: Accepted (2026-06-07)

**Reference**: [`docs/scanners/schedule.md`](../scanners/schedule.md) for the
current behaviour and entry shape. This ADR captures the schema decision for
issue #28, slice 3. It builds on [ADR 0002](0002-schedule-scanner.md), which
established `cron` as a normalised five-field string.

## Context

Laravel exposes seven sub-minute frequency helpers:

- `everySecond` (1), `everyTwoSeconds` (2), `everyFiveSeconds` (5),
  `everyTenSeconds` (10), `everyFifteenSeconds` (15), `everyTwentySeconds`
  (20), `everyThirtySeconds` (30).

ADR 0002 normalises every recognised frequency helper into the `cron` field as
a canonical five-field expression. Sub-minute intervals break that model: a
five-field cron expression has a one-minute floor. `* * * * *` is the finest a
standard crontab can express. There is no field for seconds.

ScheduleScanner previously listed sub-minute helpers as unsupported — they fell
through to `cron: null` with no other signal, indistinguishable from an
unrecognised helper or a non-static argument. Consumers asking "what runs every
ten seconds" had nothing to read.

## Decision

Emit a new structured field `frequency` on every schedule entry:

```json
"frequency": { "unit": "seconds", "every": 10 }
```

- For the seven sub-minute helpers, `frequency` carries the interval and
  `cron` stays `null`. A sub-minute schedule has no honest five-field cron, so
  we do not fabricate one.
- For every other entry — cron-based or unresolved — `frequency` is `null`.

`unit` is backed by the `FrequencyUnit` enum, which currently has a single
case (`seconds`). The enum exists so future units (sub-second, or other
non-cron intervals) extend the field rather than overload the cron string.

**Last-wins, both directions.** A chain mixing cron-based and sub-minute
helpers mirrors Laravel's runtime last-wins behaviour, the same rule ADR 0002
§3 set for conflicting frequencies:

- A sub-minute helper after a cron helper sets `frequency` and clears `cron`.
- A cron helper after a sub-minute helper sets `cron` and clears `frequency`.

At most one of `cron` / `frequency` is non-null on any entry.

## Alternatives considered

1. **Structured `frequency: { unit, every }` object — CHOSEN.** Explicit and
   machine-readable. A consumer reads `frequency.every` directly; no parsing,
   no convention to memorise. The two non-cron-expressible fields (unit,
   interval) live in their own typed field instead of being smuggled into a
   string. Extensible: new units land as new `FrequencyUnit` cases without
   touching `cron`.

2. **Non-standard six-field cron extension** (a leading seconds field, e.g.
   `*/10 * * * * *`). Rejected. It overloads `cron` with a convention no
   standard crontab parser understands. ADR 0002 chose a string precisely
   because every downstream consumer already speaks five-field cron; a
   six-field variant silently breaks all of them, and consumers can't tell a
   five-field from a six-field expression without counting.

3. **`cron: null` plus a constraint string** (e.g. `"everySeconds(10)"` in
   `constraints[]`). Rejected. `constraints[]` carries restrictions evaluated
   *in addition to* the cron tick (ADR 0002 §3) — a frequency is not a
   constraint, and folding one in conflates two distinct concepts. It would
   also leave the interval as an opaque string a consumer has to parse.

## Consequences

**Good:**

- Sub-minute schedules are visible. "What runs every ten seconds" is a field
  read, not a gap.
- `cron` keeps its five-field-only invariant. No consumer that parses `cron`
  has to handle a non-standard shape.
- The `FrequencyUnit` enum gives the field a typed source of truth and room to
  grow without reopening this decision.
- Schema addition is bounded: one new field on `scheduleEntry`, one new
  `$defs` entry, no change to existing fields.

**Costs:**

- One more nullable field on every schedule entry, and a `cron` / `frequency`
  mutual-exclusion rule consumers must understand (at most one is non-null).
- The sub-minute helper table is hand-maintained, the same maintenance cost
  ADR 0002 §3 already carries for cron helpers. A new Laravel sub-minute
  helper needs a table entry.
