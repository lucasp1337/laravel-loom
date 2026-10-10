# ADR 0002 — ScheduleScanner: hybrid discovery, normalized cron, opaque constraints

**Status**: Accepted (2026-05-18)

What the scanner matches today is in the `ScheduleScanner` and `ScheduleChainVisitor` docblocks. This ADR keeps the decisions behind it.

## Context

The scheduler callback declares what runs on cron and was invisible to Loom, so "which jobs run on cron" and "is this job orphaned (never dispatched, never scheduled)" had no answer. The design questions were which registration paths to read, how to turn a fluent chain and about 30 frequency helpers into one schema field, and how a scheduled job relates to `jobs[]`.

## Decision

1. **Read every registration path.** `Console\Kernel::schedule()`, `->withSchedule()` in `bootstrap/app.php`, and `Schedule::` facade calls under the scan paths, merged on `(file, line)`. The kernel form is still common in upgraded apps and the facade form is the only one packages use; leaving either out is a blind spot.
2. **Emit one entry per chain, from the root call.** The visitor reads on `leaveNode`, finds the outermost call whose innermost receiver is the `$schedule` variable or the `Schedule` facade, then walks the links. The root call decides `kind` and `target`; the other links decide cron, constraints and flags.
3. **`cron` is a canonical five-field string.** Every recognised helper maps to one expression and `cron(string)` passes through verbatim. When several frequency helpers are chained the last wins, as at runtime. A missing, unknown or non-literal helper gives `null`.
4. **Constraints stay opaque.** `->between`, `->weekdays`, `->when`, `->skip` and friends are `constraints[]` strings, not folded into cron: Laravel evaluates them in addition to the cron tick, and `when(closure)` is not static.
5. **Four `kind` values.** `command` (signature string or FQCN), `job` (FQCN), `closure` (`null` target for inline closures, `Class::method` for tuple and `Class@method` callables) and `exec` (shell string).
6. **The join to jobs is one-directional.** `scheduled_tasks[].target` carries the job FQCN and consumers join against `jobs[]` client-side. There is no `jobs[].scheduled` flag: it would hide a target that is missing from `jobs[]`, and it would widen the job schema for one consumer.

## Rejected

- A single discovery path (facade only).
- A structured `{minute, hour, day, month, weekday}` object. Every consumer wants a string, and the `cron()` passthrough would force a parser anyway.
- Folding constraints into the expression.
- Resolving `Schedule::macro()` helpers: macros are runtime registrations.

## Consequences

- The helper table is hand-maintained; an unrecognised helper shows up as `cron: null` and the table is extended.
- Two closures on the same line collapse to one entry.
- Discovery touches `bootstrap/app.php`, which is brittle if the `Application::configure` chain shape changes.
