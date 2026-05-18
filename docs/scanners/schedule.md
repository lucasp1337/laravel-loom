# ScheduleScanner

Discovers entries declared in Laravel's task scheduler and emits the
`scheduled[]` section of the index.

See [ADR 0002](../adr/0002-schedule-scanner.md) for the load-bearing design
decisions (discovery strategy, cron normalisation, cross-link shape).

## What it detects

ScheduleScanner walks three discovery surfaces and merges results by
`(file, line)`:

1. **`Console\Kernel::schedule(Schedule $schedule)`** — any class
   declaration in `app/Console/Kernel.php` carrying a `schedule()` method
   whose first parameter is typed `Illuminate\Console\Scheduling\Schedule`.
   The method body is walked.

2. **`bootstrap/app.php` `->withSchedule(...)`** — the
   `Application::configure(...)` chain is located, the
   `->withSchedule(function (Schedule $schedule) { ... })` link is found,
   and the closure body is walked.

3. **`Schedule` facade calls** — every PHP file under `app/` is parsed,
   and every top-level expression of the form
   `Schedule::call(...)`, `Schedule::command(...)`, `Schedule::job(...)`,
   or `Schedule::exec(...)` is captured (where `Schedule` resolves to
   `Illuminate\Support\Facades\Schedule`). Useful for schedules registered
   in service providers or in package boot logic.

Each captured root call is the start of a fluent chain. The visitor walks
down the chain collecting `(methodName, args)` for every link, then
emits one entry per chain.

## Output

One entry per chain, conforming to `$defs/scheduleEntry`:

```json
{
  "kind": "command",
  "target": "mail:send {--queue=default}",
  "cron": "0 13 * * *",
  "timezone": "America/Chicago",
  "without_overlapping": true,
  "on_one_server": false,
  "run_in_background": false,
  "constraints": ["weekdays"],
  "file": "app/Console/Kernel.php",
  "line": 28
}
```

Field semantics:

- **`kind`** — `command` | `job` | `closure` | `exec`. Determined by the
  root method of the chain (`command`, `job`, `call`, `exec`).
- **`target`** — depends on `kind`:
  - `command`: the signature string (`"mail:send"`,
    `"mail:send {--queue=default}"`) or, when the argument is a class
    constant (`SendMail::class`), the FQCN.
  - `job`: the FQCN, resolved via NameResolver from `new X(...)` or
    `X::class`.
  - `closure`:
    - `null` for inline closures (`fn () => ...`, `function () { ... }`);
      identification falls back to `file:line`.
    - `"FQCN::method"` for callable tuples (`[Cls::class, 'method']`) and
      Laravel callable strings (`'App\\Cls@method'`).
  - `exec`: the shell command string verbatim.
- **`cron`** — five-field normalised cron expression (`"*/5 * * * *"`) or
  `null` if no recognised frequency helper appears in the chain. The
  recognised set is enumerated in ADR 0002 §3.
- **`timezone`** — string from `->timezone('America/Chicago')` or `null`.
- **`without_overlapping`** — `true` if `->withoutOverlapping()` appears.
- **`on_one_server`** — `true` if `->onOneServer()` appears.
- **`run_in_background`** — `true` if `->runInBackground()` appears.
- **`constraints[]`** — opaque string labels for non-cron restrictions.
  Sorted ascending. Recognised shapes:
  - Day-of-week: `"weekdays"`, `"weekends"`, `"sundays"`, `"mondays"`,
    `"tuesdays"`, `"wednesdays"`, `"thursdays"`, `"fridays"`,
    `"saturdays"`.
  - Time-window: `"between(8:00,17:00)"`, `"unlessBetween(8:00,17:00)"`.
    Falls back to `"between(closure)"` / `"unlessBetween(closure)"` when
    the arguments aren't scalar strings.
  - Conditional: `"when(closure)"`, `"skip(closure)"`. The closure body
    is not analysed.
  - Environment: `"environments(production,staging)"` for scalar args
    (including a single array literal); `"environments(closure)"`
    otherwise.
- **`file`, `line`** — the position of the root method call in the chain
  (`$schedule->command(...)`, not the trailing `->onOneServer()`).

Entries are sorted by `(file, line)` ascending.

`stats.scheduled` is added to the top-level stats block as the count of
entries.

## Cross-link behavior

ScheduleScanner participates in cross-link only as a *source*. No fields
on existing primitives are widened.

- `scheduled[*].target` with `kind: "job"` carries the job FQCN.
  Consumers join client-side against `jobs[*].fqcn`. There is no
  `jobs[*].scheduled` flag — see ADR 0002 §5 for rationale.
- Dispatch sites inside a scheduled closure (`Schedule::call(fn () =>
  event(new X))`) are **not** captured by DispatchScanner. Closures of
  every kind are a documented skip in `dispatches.md`.

## Expected behavior

- **Standard kernel form**: `protected function schedule(Schedule
  $schedule) { $schedule->command('foo:bar')->daily(); }` — one entry,
  `cron: "0 0 * * *"`.
- **L11 closure form**: `->withSchedule(function (Schedule $schedule) {
  $schedule->job(new ProcessOrder)->hourly(); })` — one entry,
  `cron: "0 * * * *"`, `kind: "job"`, target FQCN-resolved.
- **Facade form in provider boot**: `Schedule::command('queue:work')
  ->everyMinute();` — picked up regardless of which class hosts the call.
- **Multiple modifiers**: every link in the chain is inspected; flags
  set, timezone captured, constraints collected.
- **`cron(string)` passthrough**: `->cron('*/5 8-17 * * 1-5')` is stored
  verbatim in `cron`.
- **Last-wins on conflicting frequencies**: chain with multiple frequency
  helpers (`->daily()->hourly()`) reflects the last one. Matches
  Laravel's runtime behaviour.
- **Tuple-callable in `->call`**: `->call([Reporter::class, 'send'])`
  emits `kind: "closure"`, `target: "App\\Reporter::send"`.
- **`Class@method` callable in `->call`**: `->call('App\\Reporter@send')`
  normalised to `"App\\Reporter::send"`.

## Known limitations

- **L10-only apps** are out of scope (AGENTS.md scopes Loom to Laravel
  11+). The kernel-form scanner happens to work on L10 too, but it's not
  a supported target.
- **Macros**: `Schedule::macro('myEvery5', ...)` then `->myEvery5()` in a
  chain — the runtime registration is invisible to static analysis. The
  resulting chain shows `cron: null`. Documented; not flagged.
- **Unrecognised frequency helpers**: the recognised set is enumerated in
  ADR 0002 §3. A helper not on that list (custom macro, future Laravel
  helper) yields `cron: null`. Fix by extending the helper table.
- **Non-static frequency arguments**: `->dailyAt($time)` where `$time`
  is a variable — `cron: null`. The chain is otherwise captured (flags,
  constraints, target).
- **Closure target identification**: an inline closure has `target:
  null`. Two `->call(closure)` chains on the same physical line collapse
  to a single entry (rare; not a real-world concern).
- **Constraint expression bodies**: `->when(fn () => $this->isActive())`
  and `->skip(...)` are captured as opaque labels (`"when(closure)"`).
  The closure body is not analysed.
- **Chained `->name('label')`**: ignored. Schedule names are useful for
  cache keys (overlap locks, etc.) but don't change runtime semantics.
  Can be added in a follow-up.
- **`->ping*()` callbacks**: `->pingBefore(url)`, `->thenPing(url)`,
  `->pingOnSuccess`, `->pingOnFailure` are not captured. They're a
  notification side-effect, not part of the schedule's identity.
- **`Bus::chain([...])` or `Bus::batch([...])` invoked inside a
  closure schedule**: not captured — closures are skipped by
  DispatchScanner. The schedule entry itself is captured (as a
  `closure`); its inner job composition is not.
- **Job FQCN not located on disk**: a `->job(SomePackage\\Cls::class)`
  whose class lives in `vendor/` resolves the FQCN via NameResolver,
  produces a valid entry, but no matching `jobs[*]` row exists.
  Consumers see this as `scheduled[*].target` pointing to a vendor class.
- **Schedule definitions outside the three discovery surfaces** (e.g. a
  schedule built via reflection inside a deferred provider) are
  invisible. There is no `unresolved_scheduled[]` mirror — that's an
  intentional gap; the unresolved framing only fits dispatches where the
  static-resolution shape is well-defined.
- **Multi-class files**: a file declaring two classes both named
  `Kernel` (legal PHP, PSR-4 violation) — the scanner walks both. The
  one with a `schedule()` method contributes; the other is silently
  skipped. Matches other scanners' multi-class handling.

## When something looks wrong

Triage checklist for missing schedule entries:

1. Is the chain rooted in `$schedule->...`, `Schedule::...`, or an
   `->withSchedule(function (Schedule $schedule) { ... })` callback? If
   not, it's one of the macro / dynamic-registration shapes — documented
   skip.
2. Is the file under `app/Console/Kernel.php`, `bootstrap/app.php`, or
   anywhere under `app/`? Other locations (e.g. `routes/console.php`
   `Schedule::call(...)` — yes, Laravel allows that) are not walked
   today. Open an issue if this matters for your app.
3. Is the chain inside a closure that's *itself* assigned to a variable
   or returned from a method? The walker handles immediate-call chains
   only.

Triage for unexpected `cron: null`:

1. Does the chain contain a frequency helper? If yes, check the helper
   name against the recognised set in ADR 0002 §3.
2. Is the helper's argument a variable rather than a literal? Variable
   arguments aren't resolved (`->dailyAt($time)` → `null`).
3. Are there multiple frequency helpers chained? Last wins, but if the
   last one is unrecognised, the entire `cron` is `null`.

Triage for unexpected `target: null` on `kind: "command"` or `"job"`:

1. The argument was a variable or computed expression. Static analysis
   couldn't resolve it.
2. For `command`: the argument was a closure-returning expression
   (`->command(fn () => 'foo')` — not valid Laravel anyway, but the
   visitor leaves `target: null` instead of crashing).

Triage for unexpected `kind: "closure"` with a non-null `target`:

1. The chain used the tuple form (`[Cls::class, 'method']`) or
   Laravel-callable form (`'App\\Cls@method'`). Both normalise to
   `FQCN::method`. This is intentional, not a bug.
