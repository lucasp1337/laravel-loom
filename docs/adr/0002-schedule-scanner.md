# ADR 0002 — ScheduleScanner: hybrid discovery, normalized cron, opaque constraints

**Status**: Accepted (2026-05-18)

**Reference**: [`docs/scanners/schedule.md`](../scanners/schedule.md) for the
current behaviour and entry shape. This ADR captures only the load-bearing
decisions for the initial design (issue #7).

## Context

Laravel's `schedule(Schedule $schedule)` callback declares what runs on cron.
The callback is currently invisible to Loom, which means consumers cannot
answer "which jobs run on cron", "which commands fire at 02:00", or "is this
job orphaned (never dispatched and never scheduled)".

A scanner that emits a top-level `scheduled[]` section is the natural fix.
The hard design questions are:

1. Which of three registration paths to support (`Console\Kernel::schedule`,
   `bootstrap/app.php` `->withSchedule()`, `Schedule::xxx()` facade calls).
2. How to walk the fluent chain `$schedule->command('x')->dailyAt('13:00')
   ->withoutOverlapping()` and what to extract from each link.
3. How to normalize Laravel's frequency helpers (~30 of them) into a single
   schema field.
4. How `scheduled[]` joins to `jobs[]` so a job scheduled on cron appears in
   both sections without double-emission.

## Decision

### 1. Hybrid discovery — all three registration paths in v1

Three discovery paths, merged on `(file, line)` for dedupe:

- **L10 form**: any class whose file path is `app/Console/Kernel.php` and
  that declares a `schedule(Schedule $schedule)` method. Walk the method
  body.
- **L11+ form**: `bootstrap/app.php`. Locate the
  `->withSchedule(function (Schedule $schedule) { ... })` chain on the
  `Application::configure(...)` call. Walk the closure body.
- **Facade form**: every PHP file under `app/`. Recognise any
  `Schedule::call(...)`, `Schedule::command(...)`, `Schedule::job(...)`,
  `Schedule::exec(...)` call expressed via the
  `Illuminate\Support\Facades\Schedule` facade. Walk each chain.

Loom advertises itself as working across Laravel 11+ apps; AGENTS.md scopes
the framework target to "Laravel 11+". L10 is therefore *not* a v1 target —
but the `Console\Kernel::schedule()` shape is identical in L11 apps that were
upgraded in place (the kernel form still works), so we keep it.

Rejected: narrowing to only `bootstrap/app.php`. The kernel form is still
common in upgraded apps, and the facade form is the only path packages use.
Omitting either leaves a documented blind spot that's not worth the saved
PR size.

### 2. Chain traversal — root-call rooted, leaveNode-driven

A schedule chain is a nested `MethodCall` tree whose innermost call is the
"root" (`$schedule->command(...)`, `$schedule->job(...)`, etc.) and whose
outermost call is the last chained modifier. The visitor:

1. On `leaveNode` of any `Expr\MethodCall`, walk down the `->var` chain
   until reaching a non-`MethodCall` receiver.
2. If the deepest receiver is a `Variable($schedule)` (kernel/closure form)
   *or* a `StaticCall` on the `Schedule` facade, this is a schedule chain.
3. Re-walk the chain top-down, collecting `(methodName, args)` for each
   link. The root link determines the entry's `kind` and `target`; the
   remaining links contribute to `cron`, `constraints`, and the boolean
   flags.

This avoids double-emission: an outer `leaveNode` on the chain root sees
the full chain, and the visitor only emits one entry per chain root.

Rejected: a top-down walk on `enterNode`. NameResolver hasn't resolved
inner `::class` constants when the outer node enters, and recursive
descent of `->var` is identical work either way.

### 3. Cron — normalised five-field string + opaque `constraints[]`

`cron` is a string holding the canonical five-field expression
(`"0 13 * * *"`) for every frequency helper Loom recognises. The recognised
set for v1 is the time-based helpers whose result is unambiguous and
stateless:

- `everyMinute`, `everyTwoMinutes`, `everyThreeMinutes`, `everyFourMinutes`,
  `everyFiveMinutes`, `everyTenMinutes`, `everyFifteenMinutes`,
  `everyThirtyMinutes`
- `hourly`, `hourlyAt(int)`
- `everyTwoHours`, `everyThreeHours`, `everyFourHours`, `everySixHours`
- `daily`, `dailyAt(string)`, `twiceDaily(int, int)`,
  `twiceDailyAt(int, int, int)`
- `weekly`, `weeklyOn(int|array, string)`
- `monthly`, `monthlyOn(int, string)`, `twiceMonthly(int, int, string)`,
  `lastDayOfMonth(string)`
- `quarterly`, `yearly`, `yearlyOn(int, int, string)`
- `cron(string)` — passed through verbatim

When the frequency helper is missing, an unknown method, or a sequence of
multiple frequency helpers is chained (which is also a Laravel bug at
runtime, but we don't crash on it), `cron` is `null`. The doc lists this as
a known limitation and a triage step.

**Multiple frequency helpers in one chain**: last-wins. Laravel's own
runtime behaviour is last-wins (each helper rewrites the internal cron
expression), so we mirror that. No "unresolved" classification — the
chain is well-formed PHP and produces *some* schedule at runtime.

**Constraints** (`->between`, `->weekdays`, `->when`, `->skip`, day-of-week
restrictions like `->mondays()`, `->sundays()` when used *after* a frequency
helper rather than as the frequency itself): emitted as a separate
`constraints[]` array of opaque strings (`["weekdays", "between(8:00,17:00)"]`).
We do NOT fold them into the cron expression — runtime constraint checks
(`when(closure)`) are non-static, and folding day-of-week restrictions into
cron diverges from Laravel's runtime semantics (constraints run *in addition
to* the cron tick, not as part of it).

Rejected: a structured `cronExpression` object with `minute`, `hour`,
`day`, `month`, `weekday` fields. Adds schema surface for no consumer
benefit — every cron library reads strings, and `cron(string)` passthrough
forces us to ship a five-field parser anyway. Stick with the string.

### 4. Kind + target — four `kind` values, target shape depends on kind

| `kind`    | Root call shape                              | `target`                          |
|-----------|----------------------------------------------|-----------------------------------|
| `command` | `->command('mail:send {--queue=default}')`  | the signature string verbatim     |
| `command` | `->command(SendMail::class)`                 | the FQCN                          |
| `job`     | `->job(new SendInvoice())`                   | the FQCN                          |
| `job`     | `->job(SendInvoice::class)`                  | the FQCN                          |
| `closure` | `->call(fn () => ...)`                       | `null` (file:line is the target)  |
| `closure` | `->call([Cls::class, 'method'])`             | `"Cls::method"`                   |
| `closure` | `->call('App\Cls@method')`                   | `"App\\Cls::method"`              |
| `exec`    | `->exec('php some.php')`                     | the shell command string          |

Tuple-form and `Class@method` callables are normalised to the
`"FQCN::method"` shape so consumers don't need to special-case them. A
`call(...)` whose argument is a variable, closure with captured state, or
non-static expression keeps `target: null` and relies on `file:line` for
identification.

### 5. Cross-link — schedule-side join only

`scheduled[*]` is the source of truth. The cross-link pass populates the
join *out of* it:

- For every `scheduled[*]` entry with `kind: "job"` and a resolvable
  `target`, no change is made to `jobs[]` directly. The link is exposed
  via `scheduled[*].target` only — consumers join client-side.

We do **not** add a `jobs[*].scheduled` flag or a `jobs[*].schedule_entries[]`
field. Reasons:

- A `scheduled[*].target` carrying a job FQCN that doesn't appear in
  `jobs[]` is already a meaningful gap (job lives outside `app/Jobs/` and
  isn't dispatch-site-seeded). A `scheduled` flag would hide it.
- The `scheduled[]` section is small (tens of entries, not thousands).
  Client-side join is cheap.
- Adding a back-pointer to `jobs[*]` widens the job schema for one
  consumer. Keeping the join one-directional follows the same shape as
  `events[*].handled_by` (events know their listeners, listeners don't
  carry a `listening_for` mirror — except they do, via `handles`, which
  is the *source* of truth there; in this case `scheduled[].target` plays
  the same source-of-truth role).

If a follow-up consumer needs `jobs[*].scheduled`, add it in a follow-up
ADR alongside that consumer.

## Consequences

**Good:**

- Three registration paths covered in one PR — no "works on v11 facade but
  not on kernel form" caveats.
- Cron is a string, which every downstream consumer (cron parsers,
  human-readable formatters, `next_run_at` calculators) already speaks.
- `constraints[]` as opaque strings keeps the schema additive: new
  constraint helpers in future Laravel versions get captured verbatim
  rather than dropped.
- Schema additions are bounded: one new top-level array, one new `$defs`
  entry, no new fields on existing primitives.

**Costs:**

- The frequency-helper recognition table is hand-maintained. Laravel adds
  helpers occasionally (`twiceDailyAt` is recent). When `cron` shows up as
  `null` because of an unrecognised helper, contributors need to know to
  extend the table — documented as a triage step.
- Last-wins on multiple frequency helpers is the runtime behaviour but
  surprises readers who expect a "conflicting frequency" warning. We
  document it; we don't flag it.
- Closures with no resolvable target rely on `file:line` for uniqueness.
  Two closures on the same line (rare) collapse to one entry.
- Discovery touches `bootstrap/app.php`, which no other scanner touches
  today. The L11 closure-callback pattern is brittle if Laravel changes
  the `Application::configure` chain shape.

## Alternatives considered

1. **Single discovery path (facade only).** Rejected: kernel form is still
   common in upgraded apps; package-registered schedules use facade form;
   neither alone covers a real app.
2. **Structured cron object `{minute, hour, day, month, weekday}`.**
   Rejected: every consumer ultimately wants a string; `cron(string)`
   passthrough would force us to parse it anyway.
3. **Fold constraints into the cron expression.** Rejected: Laravel runs
   constraints *in addition to* the cron tick, not as part of it.
   `->weekdays()->dailyAt('9:00')` is not the same as `0 9 * * 1-5` once
   `->skip(closure)` is also in the chain.
4. **`jobs[*].scheduled` boolean back-pointer.** Rejected: hides
   target-not-in-jobs gaps and widens the job schema for one consumer.
   One-directional join via `scheduled[].target` is sufficient.
5. **Resolve `Schedule::macro(...)`-registered helpers.** Rejected: macros
   are runtime registrations. Out of scope; documented as a limitation.
