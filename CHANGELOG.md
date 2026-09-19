# Changelog

All notable changes to `laravel-loom` will be documented in this file. This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Breaking

- Index files from 0.2 no longer validate. Required fields added without a `loom_version` bump: `closure_listeners[].end_line`, `scheduled[].name`, `scheduled[].even_in_maintenance_mode`. Re-run `loom:scan`. See [Upgrading](docs/upgrading.md).
- `livewire/livewire` and `laravel/mcp` (^1.0) are now installed with the package.

### Added

- Browser UI at `/loom`: dashboard, tables, entity pages, chain graph and search. Local-only: other environments must be listed in `ui.environments`, and `production` also needs `ui.allow_in_production`. See [docs/guides/browse-the-ui.md](docs/guides/browse-the-ui.md).
- `loom:mcp`: read-only MCP server over stdio with 11 tools for AI agents. See [docs/guides/ask-an-agent.md](docs/guides/ask-an-agent.md). ([#66](https://github.com/lucasp1337/laravel-loom/pull/66))
- GitHub Action that runs `loom:scan`, `loom:check` and a base-branch diff, then comments on the PR. See [docs/reference/action.md](docs/reference/action.md). ([#65](https://github.com/lucasp1337/laravel-loom/pull/65))
- `loom:check`: baseline growth, orphan and cyclic-dispatch rules, and `--skip`. ([#65](https://github.com/lucasp1337/laravel-loom/pull/65))
- PHP API to read an index: `IndexLoader` and typed value objects. See [docs/reference/php-api.md](docs/reference/php-api.md). ([#60](https://github.com/lucasp1337/laravel-loom/pull/60))
- `routes[]` section: HTTP routes from `routes/`, with the events and jobs dispatched by each controller method. `loom_version` is now `0.3.0`. ([#57](https://github.com/lucasp1337/laravel-loom/pull/57), [#58](https://github.com/lucasp1337/laravel-loom/pull/58))
- `mailables[]` and `notifications[]` sections.
- `scheduled[]` gains `name`, `even_in_maintenance_mode`, `arguments`, `queue`, `connection` and `without_overlapping_expires_at`. ([#59](https://github.com/lucasp1337/laravel-loom/pull/59))
- Sub-minute schedules (`everySecond` to `everyThirtySeconds`) report `frequency: {unit, every}`. ([#59](https://github.com/lucasp1337/laravel-loom/pull/59))
- Tasks inside `Schedule::group()` inherit the group's frequency and modifiers. ([#59](https://github.com/lucasp1337/laravel-loom/pull/59))
- `daysOfMonth()` schedule helper. ([#63](https://github.com/lucasp1337/laravel-loom/pull/63))
- `broadcast()`, `broadcast_if()` and `broadcast_unless()` count as event dispatches; so do conditional `dispatchIf`/`dispatchUnless` and `Event::listen([A, B], ...)`. ([#62](https://github.com/lucasp1337/laravel-loom/pull/62), [#63](https://github.com/lucasp1337/laravel-loom/pull/63))
- `closure_listeners[].dispatches` is now populated.
- `overrides` on dispatch sites (queue, connection, delay and similar modifiers) and `channels` on `Notification::send()` sites.
- `composer bench` performance suite that gates on counts, not wall time. See [benchmarks/README.md](benchmarks/README.md). ([#61](https://github.com/lucasp1337/laravel-loom/pull/61))
- `ScheduleScanner` and `JobsScanner` for `scheduled[]` and `jobs[]`.

### Changed

- Dispatch targets behind a fluent chain (`X::class` or `new X` under modifiers) now resolve to their class name.
- Jobs and listeners report `queued: true` when a parent class or trait implements `ShouldQueue`.

## [0.2.0](https://github.com/lucasp1337/laravel-loom/compare/v0.1.0...v0.2.0) - 2026-05-17

### Changed

- **BREAKING — `listeners[*].handles` shape.** The array now holds `{event, method}` objects instead of bare event-FQCN strings. Both fields are always present; `method` defaults to `"handle"` for registrations that didn't name one (auto-discovery, bare `Listener::class` in `$listen`, bare `Listener::class` in `Event::listen()`). Entries are deduped by the `(event, method)` tuple and sorted by `event` then `method`.

  Migration: consumers reading `listener['handles']` previously got `["App\\Events\\OrderPlaced"]`; they now get `[{"event": "App\\Events\\OrderPlaced", "method": "handle"}]`. Extract the legacy shape with `array_map(fn ($h) => $h['event'], $listener['handles'])`.

- **BREAKING — `events[*].handled_by` shape.** The array now holds `{listener, method}` objects instead of bare listener-FQCN strings. Sorted by `listener` ascending then `method` ascending.

  Migration: consumers reading `event['handled_by']` previously got `["App\\Listeners\\SendOrderConfirmation"]`; they now get `[{"listener": "App\\Listeners\\SendOrderConfirmation", "method": "handle"}]`. Extract the legacy shape with `array_map(fn ($h) => $h['listener'], $event['handled_by'])`.

- `LOOM_VERSION` bumped to `0.2.0` to reflect the breaking schema changes.

### Added

- **Closure listener discovery.** A new top-level `closure_listeners[]` section captures closure and arrow-function registrations that `listeners[]` can't represent (no FQCN). Three discovery paths: closure values inside the `$listen` array on `EventServiceProvider` (`registration: "listen_array"`), closures as the second argument to `Event::listen()` anywhere under `app/` (`registration: "event_listen_call"`), and closures in a subscriber's `subscribe()` return-array (`registration: "subscriber"`). Both `fn ($e) => …` and long-form `function ($e) { … }` are detected. The `event` field holds the FQCN for `::class` registrations and the raw string for string-keyed registrations; `file`/`line` point to the closure node. The section is additive — existing consumers reading `listeners[]` see no shape change. `queued` is always `false` and `dispatches` is always `[]` in this release; both are reserved fields. `events[*].handled_by` does not link back to closures (their shape lacks an FQCN + method); filter `closure_listeners[]` by `event` instead. See [docs/reference/what-loom-detects.md](docs/reference/what-loom-detects.md).

- **Multi-handler listener support.** A single listener class registered against different events under different methods is now represented faithfully. Tuple-form registrations preserved across all four discovery paths:
  - `$listen` array: `EventClass::class => [[Listener::class, 'handleFoo']]` records `method: "handleFoo"`.
  - `Event::listen(EventClass::class, [Listener::class, 'handleFoo'])` records `method: "handleFoo"`.
  - Subscriber return-array tuple values `[Event::class => [self::class, 'handleFoo']]` and `[Event::class => [Subscriber::class, 'handleFoo']]` record `method: "handleFoo"`.
  - Bare `Listener::class` forms (in `$listen`, in `Event::listen()`) and auto-discovery default to `method: "handle"`.

  A listener can carry multiple `handles[]` entries for the same event under different methods; the dedupe key is the full `(event, method)` tuple.

- **ListenerScanner — subscriber discovery.** Detects subscriber classes registered via `$subscribe = [Subscriber::class, …]` on `EventServiceProvider` (or any class extending it) and via `Event::subscribe(Subscriber::class)` calls. The subscriber's own `subscribe()` method is parsed for return-array form (`return [Event::class => 'method', …]`), and the resulting events populate `handles[]`. Subscribers are emitted with `registration: "subscriber"` — a new highest-precedence source above `listen_array`.

- **ListenerScanner — imperative `subscribe()` body support.** The subscriber visitor now also walks the `subscribe()` method body in addition to its return-array, parsing `$events->listen(...)` calls against the dispatcher parameter. The dispatcher is identified by parameter position (any name, any type-hint). Control-flow statements (`if`, `foreach`, `try/catch`) are descended; nested closures and other method bodies are not. Routing: `[self::class | static::class | OwnFqcn::class, 'method']` and bare-string callables `'method'` contribute to the subscriber's own `handles[]`; `[OtherClass::class, 'method']` registers `OtherClass` as a regular `listeners[]` entry; closures and arrow functions flow into `closure_listeners[]`. All three forms are stamped `registration: "subscriber"`. A single subscriber can mix the return-array and imperative forms. **Behavior change:** when an imperative `$events->listen(...)` registers a *foreign* listener (one not declared on the subscriber's own class), that listener's `registration` is upgraded to `subscriber` — the highest-precedence source. Previously such foreign listeners were either invisible (the body wasn't parsed) or would carry their lower-precedence source. The schema is unchanged; the precedence rule (`subscriber > listen_array > event_listen_call > auto_discovered`) is unchanged. Out of scope and documented as gaps: nested `$events->subscribe(...)` calls inside `subscribe()`, registrations hidden inside nested closures or chained iterator callbacks (`collect()->each(fn () => $events->listen(...))`), and `listen(...)` calls on receivers other than the dispatcher parameter (`$this->dispatcher->listen(...)`).

### Fixed

- Handler `dispatches[]` no longer lists mail/notification sends; they feed `sent_from` / `notified_from`. ([#76](https://github.com/lucasp1337/laravel-loom/pull/76))
- **Cross-link Phase 3 dispatch attribution for non-`handle` listener methods.** Previously, the listener-dispatch join keyed on the literal method name `handle`, so dispatches emitted from `handleOrderPlaced()` / `handleRefund()` / any custom handler method were silently dropped from `listeners[*].dispatches`. The join now matches when the dispatch's enclosing method is in the listener's `handles[*].method` set, so dispatches from custom handler methods are attributed to the listener.

## [0.1.0](https://github.com/lucasp1337/laravel-loom/releases/tag/v0.1.0) - 2026-05-16

First public release.

### Added

- **Four static scanners** covering the primitives of Laravel's event-driven architecture:
  - `EventScanner` — event classes from `app/Events/` plus any class dispatched via `event()`, `Event::dispatch()`, or `X::dispatch()`.
  - `ListenerScanner` — listeners registered through the `$listen` array on `EventServiceProvider`, Laravel 11+ auto-discovery via typed `handle()` parameters, and `Event::listen()` calls anywhere under `app/` (DDD-style providers outside `app/Providers/` are supported). Dedupe precedence: `listen_array > event_listen_call > auto_discovered`.
  - `ObserverScanner` — observers registered via `Model::observe()` (including `static::observe(...)` in `booted()`), the `#[ObservedBy]` attribute, and `Event::listen('eloquent.*', …)` listener strings. Emits both `observers[]` and synthesized `model_events[]`. Dedupe precedence on same `(observer, model)` pair: `attribute > observe_call`.
  - `DispatchScanner` — one-level dispatch-site scan of every class method body. Recognises `event()`, `Event::dispatch()`, `X::dispatch()`, `dispatch()`, and `Bus::dispatch()`. Surfaces unresolvable dispatches as `unresolved_dispatches[]` entries with one of four reason codes.
  
- **Cross-link pass** in `IndexBuilder` joining the scanners: populates `events[*].handled_by` from listener registrations, disambiguates Dispatchable-form (`X::dispatch()`) sites against the events index, then populates `listeners[*].dispatches`, `observers[*].dispatches`, and `events[*].dispatched_from`.
- **Two artisan commands:** `loom:scan` writes `storage/loom/index.json`; `loom:show [filter]` prints the index (optionally filtered by FQCN substring).
- **JSON Schema** at `schema/loom-index.schema.json` — every emitted index is validated before being written. Validation failure is fatal.
- **Docker development environment** plus a `Justfile` so contributors without `ext-mbstring`, `ext-xml`, `ext-dom`, or `ext-xmlwriter` on their host PHP can still run the full toolchain.
- **Coverage reporting** via Codecov on push to `main` and pull requests.
- **Contributor documentation** under `docs/`: architecture, schema, per-scanner behavior and known limitations, contributing guide.
- **GitHub Actions** workflows for Pest (matrix across PHP 8.3 / 8.4 / 8.5 × Laravel 11 / 12 / 13 × prefer-lowest / prefer-stable), PHPStan level 8, Pint auto-formatting, coverage upload, dependabot auto-merge, and changelog updating on release.

### Requirements

- PHP **8.3+**
- Laravel **11, 12, or 13**
