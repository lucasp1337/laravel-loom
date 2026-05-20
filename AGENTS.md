# AGENTS.md

Operational guide for AI agents working on this repo. Humans should read `README.md` and `docs/` instead — those are written for you.

This file is for the parts that don't fit anywhere else: conventions that aren't enforced by tests, mistakes that have been made before, and the boundaries between subagents.

---

## Scope

Loom emits a JSON index of event-driven Laravel primitives. Sections emitted today: `events`, `listeners`, `closure_listeners`, `observers`, `model_events`, `jobs`, `scheduled`, `mailables`, `notifications`, `unresolved_dispatches`. Planned for v1.0: routes — tracked under the [v1.0 milestone](https://github.com/lucasp1337/laravel-loom/milestone/1).

Anything an agent codes against must already exist in `schema/loom-index.schema.json`. The schema rejects unknown top-level properties; don't introduce new sections without going through the schema-guardian.

Out of scope, and not just "later":

- Data-model and access-control primitives — models, migrations, validators, policies, gates
- Runtime tracing / queue worker observation
- Code-quality metrics (complexity, fat-class detection, line counts)
- Visualization features beyond the read-only browser UI in #19
- Laravel < 11

Loom's domain is the *control flow* of a Laravel app — what dispatches what, what handles what, what runs when. Routes and schedules are part of that (an HTTP request fires a controller method; cron fires a job at 2am), even though they aren't strictly "event-driven" in the `Event::dispatch()` sense. Models and migrations are not.

When a subagent proposes a feature, the test is: *does this enrich the existing schema, make an existing consumer more useful, or close a documented gap?* If not, decline or file an issue for later.

---

## Repo layout

```
src/
  LoomServiceProvider.php          # registers the two artisan commands
  Console/
    ScanCommand.php                 # loom:scan — writes storage/loom/index.json
    ShowCommand.php                 # loom:show [filter] — prints the index
  Contracts/
    Scanner.php                     # the one-method contract every scanner implements
  Index/
    Index.php                       # immutable value object — serializes to JSON
    IndexBuilder.php                # orchestrates scanners + runs the cross-link pass
  Scanners/
    EventScanner.php
    ListenerScanner.php
    ObserverScanner.php
    JobsScanner.php
    ScheduleScanner.php
    MailableScanner.php
    NotificationScanner.php
    DispatchScanner.php
    Visitors/                       # PhpParser NodeVisitorAbstract subclasses
  Support/
    AstWalker.php                   # parser + NameResolver wrapper
    ClassHierarchyResolver.php      # cross-file extends/implements/use-trait resolver (lazy, per-build)

schema/
  loom-index.schema.json           # the contract for every index Loom emits

tests/
  Unit/                             # visitor-level tests, heredoc snippets
  Feature/                          # scanner + IndexBuilder tests, fixture-driven
  Fixtures/                         # minimal app trees per scenario

docs/                               # contributor docs — start here for everything else
```

---

## Conventions that aren't enforced by tests

These have caused regressions. Don't rediscover them.

**Visitors read on `leaveNode`, not `enterNode`.** `AstWalker` attaches `NameResolver` first. NameResolver rewrites `Node\Name` references as it descends — so by the time you're in `enterNode` for an outer node (e.g. a `FuncCall`), the inner `New_->class` or `ClassConstFetch->class` you want to read has NOT been resolved yet. `EventClassVisitor` is the one exception (it reads `$node->namespacedName` on the class itself, which NameResolver sets before descent). Everywhere else, use `leaveNode`. We've shipped this bug at least twice.

**Visitors reset state in `beforeTraverse()`.** Scanners reuse a single visitor instance across all files in a discovery loop. Forgetting to reset means dispatch sites from file A bleed into file B's reported sites.

**One source of truth per output field.**
- `events[*].handled_by` — populated by the cross-link pass from `listeners[*].handles`. Each entry is a `{listener, method}` pair. Listener scanners don't write to event entries. Closure registrations in `closure_listeners[]` are intentionally NOT joined back into `handled_by` — that field's shape requires an FQCN + method, which closures lack.
- `events[*].dispatched_from` — populated by the cross-link pass from DispatchScanner's `_dispatch_sites`.
- `listeners[*].dispatches` / `observers[*].dispatches` / `jobs[*].dispatches` — same; cross-link from DispatchScanner. The job join keys on enclosing method `handle`.
- `jobs[*].dispatched_from` — populated by the cross-link pass from dispatch sites with finalized `kind === 'job'` matching a job FQCN. Same model as `events[*].dispatched_from`; both reference `$defs/dispatchSite`.
- `mailables[*].sent_from` and `notifications[*].notified_from` — populated by the cross-link pass from dispatch sites with finalized `kind === 'mailable'` / `kind === 'notification'`. Same `$defs/dispatchSite` shape. `DispatchSiteVisitor` emits the corresponding `provisionalKind` values; cross-link phase 5 joins them.
- `closure_listeners[*].dispatches` — reserved; currently always `[]`. Line-span-based attribution from DispatchScanner is a planned follow-up.
- `model_events` — emitted directly by ObserverScanner. The cross-link does NOT regenerate them.

If two scanners ever write to the same field, you've drifted from the design — fix the drift, don't merge the writes.

**`_dispatch_sites` is internal.** DispatchScanner returns `['unresolved_dispatches' => …, '_dispatch_sites' => …]`. The underscore-prefixed section feeds the cross-link pass and is stripped before the `Index` is constructed. The JSON schema rejects it via `additionalProperties: false` — that's intentional, not a bug.

**Static only.** No `app()->make()`, no `Event::listen()` at runtime, no `\ReflectionClass::getMethods()` for anything Loom analyzes. Loom reads source files via `file_get_contents`. Reflection is fine for inspecting the running Laravel application (e.g. `Application::VERSION`), not for inspecting the app being scanned.

**Unresolved dispatches are first-class output.** When DispatchScanner sees `event($var)` or `event("App\\Events\\{$x}")`, it MUST emit an `unresolved_dispatches` entry. Silently dropping these is a regression. The four reason codes are fixed by the schema: `dynamic_class_name`, `container_resolution`, `string_concatenation`, `conditional_dispatch`.

**Cite the schema section in commit messages when changing scanner output.** Reviewers (and future you) will thank you. Example: `feat(listeners): widen $listen walk to app/ (cites $defs/listener)`.

**DTOs, not associative arrays, for inter-component data.** Visitors emit `list<SomeDto>` from `src/Dto/`, never `array<int, array{...}>`. Scanners consume DTOs and only build the schema-shaped associative arrays at the emit boundary (the final step of `scan()`). The cross-link pass operates on the schema-shape since that IS the JSON contract — but everything before that boundary is typed. See `docs/contributing.md#data-transfer-dtos-not-arrays`.

---

## The cross-link pass

`IndexBuilder::crossLink()` is the only place that reads cross-scanner data. Five phases, in order:

1. **`events[*].handled_by`** — listeners' `handles` `{event, method}` pairs inverted onto matching event entries as `{listener, method}` pairs
2. **Disambiguate `kind: ambiguous`** — Dispatchable-form sites (`X::dispatch(...)`) get `kind = event` if their target is in `events[]`, else `kind = job`
3. **`listeners[*].dispatches`** — sites whose enclosing context is a listener FQCN + enclosing method is in that listener's `handles[*].method` set
4. **`observers[*].dispatches` and `jobs[*].dispatches`** — sites whose enclosing context is an observer FQCN + method is a canonical Eloquent hook, or whose enclosing context is a job FQCN + enclosing method is `handle`
5. **`events[*].dispatched_from` and `jobs[*].dispatched_from`** — sites with finalized `kind === 'event'` matched to event entries, plus sites with finalized `kind === 'job'` matched to job entries

After cross-link: strip `_dispatch_sites` from the merged sections before constructing the `Index`. Schema validation happens against the stripped payload.

---

## The subagent fleet

| Agent | Domain | When to invoke |
|---|---|---|
| `scanner-architect` | Scanner design, discovery strategy, three-concern separation | Adding or redesigning a scanner. Writes a design doc. |
| `ast-specialist` | `nikic/php-parser` visitors, NameResolver edge cases | Writing or fixing AST-traversal code |
| `schema-guardian` | `schema/loom-index.schema.json` | Any change to output shape; veto power on the schema |
| `test-engineer` | Pest tests, fixture apps, Testbench harness | After any scanner or IndexBuilder change |
| `quality-inspector` | PHPStan level 8, Pint, AST-code smells | Pre-commit, post-feature |
| `doc-writer` | README, sample outputs, CHANGELOG, scanner docs | User-facing prose changes |

Slash commands wire chains together:
- `/add-scanner <Name>` — architect → schema-guardian → ast-specialist → test-engineer → quality-inspector → doc-writer
- `/run-checks` — PHPStan + Pint + Pest, halt on first failure (run inside Docker if your host lacks ext-xml etc.)
- `/scan-self <fixture>` — exercise the scanners against a fixture app and inspect the output
- `/prep-release <version>` — version bump, changelog assembly, tag check
- `/validate-schema <path>` — validate an arbitrary JSON file against `schema/loom-index.schema.json`

---

## Tech invariants

- PHP 8.3+, Laravel 11+
- `nikic/php-parser` for all AST work — no regex parsing of PHP source
- `justinrainbow/json-schema` for validation
- PHPStan level 8, zero errors
- Pint Laravel preset, fixtures excluded via `pint.json`
- Pest 3 + Orchestra Testbench

Local environment may lack `ext-dom`/`ext-xml`/`ext-mbstring`/`ext-xmlwriter`. The Dockerfile at the repo root provides those: `docker build -t laravel-loom-dev:latest .` then `docker run --rm -v "$(pwd):/app" laravel-loom-dev:latest vendor/bin/pest`.

---

## Things to NEVER do

- **Use `php artisan event:list` as a data source.** Loom re-derives from source for accuracy, to surface things Laravel's command misses (observers, dispatch sites, unresolved dispatches with file/line), and to work on a checked-out repo without booting the app. The runtime command requires a fully-booted Laravel app; Loom does not.
- **Modify the schema without `schema-guardian` review.** Schema changes are breaking or near-breaking; they need explicit version-bump reasoning.
- **Add CLI flags or output formats** beyond `loom:scan` (no flags) and `loom:show [filter]` (one optional positional argument). The two-command surface is deliberately frozen.
- **Skip cases silently when you can emit `unresolved_dispatches`.** Better to flag a gap than hide it.
- **Commit failing checks.** PHPStan, Pint, and Pest all green before any commit lands.
- **Add dependencies without strong justification.** Every new dependency is a contributor friction point.

---

## When you are uncertain about scope

See the **Scope** section near the top of this file for what's emitted today vs planned for v1.0. If something doesn't fit either list, stop and ask a human — don't bolt it onto an existing scanner. A genuinely new primitive gets its own scanner via `/add-scanner`.

The behavior contract is what the code does plus what `docs/` says. If you have to choose, the code wins — fix the docs.
