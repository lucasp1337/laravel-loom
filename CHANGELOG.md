# Changelog

All notable changes to `laravel-loom` will be documented in this file. This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased](https://github.com/lucasp1337/laravel-loom/compare/v0.1.0...HEAD)

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
