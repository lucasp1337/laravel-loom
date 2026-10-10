# What Loom detects

One line per construct, with the test that pins it. Field meanings are in the [schema](schema.md); symptoms and fixes are in [Why was my code missed](../guides/why-was-my-code-missed.md).


## Events

| Construct | Result | Test |
| --- | --- | --- |
| Classes in `app/Events/`, abstract included | yes | [EventScannerTest] |
| Targets of `event()`, `broadcast()`, `broadcast_if/unless()`, `Event::dispatch()` | yes, anywhere | [EventDispatchSiteVisitorTest] |
| `X::dispatch/dispatchIf/dispatchUnless()` | only for classes in `app/Events/` | [ClassSpecsTest] |
| Model `$dispatchesEvents` (literal hook, `Foo::class`) | yes, as dispatched by the model | [DispatchesEventsVisitorTest] |
| Dynamic targets, anonymous classes | no; dynamic ones are `unresolved_dispatches` | [DispatchScannerTest] |

## Listeners

| Construct | Result | Test |
| --- | --- | --- |
| Public `handle*` or `__invoke` with a first parameter in `app/Listeners/`; declared, inherited or from a trait (`as`, `insteadof`) | yes, as Laravel discovers them | [HandlerResolutionTest] |
| Nullable and union parameter types | one event per class | [ListenerScannerTest] |
| Untyped or builtin first parameter | listed with `handles: []` | [ListenerScannerTest] |
| Abstract classes, traits, no parameter, `as protected` | no | [ListenerScannerTest] |
| `$listen`: bare, tuple, `Closure::fromCallable()`, `Listener::method(...)` | yes | [ListenArrayVisitorTest] |
| `Event::listen(...)` from any class, facade alias or FQCN, event or array of events | yes | [EventListenCallVisitorTest] |
| Dispatcher from `$this->app['events']`, `app()`, `resolve()`, `make()`, or a variable assigned from them | yes | [EventListenCallVisitorTest] |
| Subscribers via `$subscribe` or `Event::subscribe()`, return-array and `$events->listen()` styles | yes, `registration: subscriber` | [SubscriberClassVisitorTest] |
| Dispatcher typed in `boot()` or held in a property, nested closures in `subscribe()`, `$obj->method`, `Class::method` strings | no | [EventListenCallVisitorTest] |

## Closure listeners

| Construct | Result | Test |
| --- | --- | --- |
| Closures and arrow functions in `$listen`, `listen()` and subscribers, keyed by `::class` or string | yes | [ListenerScannerEndToEndTest] |
| `Event::listen(function (X $e) {...})` | event from the first parameter; untyped, `object`, `mixed` skipped | [EventListenCallVisitorTest] |
| Dispatches in the body, nested closures included | yes, by source span | [ClosureDispatchAttributionPhaseTest] |
| Queue status, back-links from `handled_by` | no (`queued` is always `false`) | [ClosureOwnerEndToEndTest] |

## Observers

| Construct | Result | Test |
| --- | --- | --- |
| `#[ObservedBy]` single and array; `Model::observe()`, `static::`, `self::` | yes | [ObservedByAttributeVisitorTest], [ObserveCallVisitorTest] |
| Hook methods of any visibility, inherited or from a trait | yes, `retrieved` to `forceDeleted` plus `trashed` on Laravel 12+ | [ObserverClassVisitorTest] |
| `Event::listen('eloquent.{hook}: {Model}', ...)` with `Class@method`, tuple or class | yes, `model_events` only | [EloquentListenStringVisitorTest] |
| `parent::observe()`, `$this->observe()`, `booting`/`booted` methods, closure handlers, container-form `eloquent.*` | no | [ObserverScannerTest] |

## Jobs

| Construct | Result | Test |
| --- | --- | --- |
| Classes in `app/Jobs/`; targets of `dispatch()`, `Bus::dispatch()`, `X::dispatch()` incl. conditional and fluent-chain forms | yes | [JobsScannerTest] |
| Class-level scalar `$connection`, `$queue`, `$delay`, `$tries`, `$timeout`, `$backoff` | yes, `null` when absent | [JobsScannerTest] |
| `queued` via a vendor parent, `backoff()`, `retryUntil()`, `ShouldBeUnique`, `Batchable` | no | [JobsScannerTest] |

## Dispatches

| Construct | Result | Test |
| --- | --- | --- |
| `event()`, `broadcast()`, `Event::dispatch()`, `dispatch()`, `dispatch_sync()`, `Bus::dispatch/dispatchSync/dispatchNow/dispatchAfterResponse()` | yes | [DispatchRuleMatcherTest] |
| `X::dispatch/dispatchIf/dispatchUnless/dispatchSync/dispatchAfterResponse()` | yes; event or job decided at cross-link | [DispatchScannerTest] |
| `Bus::chain([...])`, `Bus::batch([...])` | one site per literal item | [BusChainBatchEndToEndTest] |
| `Queue::push/pushOn/later/laterOn/bulk`, `->afterResponse()` | yes, with a `mode` | [DispatchModeEndToEndTest] |
| Queue, connection, integer delay, `afterCommit`, locale, mailer | yes, as `overrides` | [ChainModifierExtractorTest] |
| Sites in methods and in closures within them (`DB::transaction`, `each`, `tap`) | owned by the enclosing method | [ClosureOwnershipPhaseTest] |
| Closures passed to `Event::listen` or `Route::get` | owned by that listener or route | [ClosureOwnershipPhaseTest] |
| Variable, concatenated, container and conditional targets | `unresolved_dispatches` | [DispatchSiteVisitorTest] |
| `Queue::pushRaw`, `Queue::before/after/failing`, first-class callables, code outside a class or route closure, non-literal delays, modifiers in a separate statement | no | [DispatchSiteVisitorTest] |

## Mailables and notifications

| Construct | Result | Test |
| --- | --- | --- |
| Classes in `app/Mail/`; `Mail::send/sendNow/queue/onQueue/queueOn/later/laterOn`, `Mail::to()/cc()/bcc()` chains | yes | [MailableScannerEndToEndTest] |
| `Mail::raw()`, `->html()`, `->text()`, variable targets | no; variables are `unresolved_dispatches` | [DispatchRuleMatcherTest] |
| Classes in `app/Notifications/`; `$x->notify()`, `notifyNow()`, `Notification::send/sendNow()`, `Notification::route(...)->notify()` | yes | [NotificationScannerEndToEndTest] |
| Literal channel filter on `send` | yes, `channels` on the site | [NotificationScannerEndToEndTest] |
| `via()` returning a literal array | yes, source order | [NotificationScannerTest] |
| Conditional or computed `via()` | `channels_dynamic: true` | [NotificationScannerTest] |
| Inherited `via()`, `shouldSend()`, message content, receiver types, facade modifiers before `send` | no | [NotificationScannerTest] |

## Schedule

| Construct | Result | Test |
| --- | --- | --- |
| `Kernel::schedule()`, `withSchedule()`, `routes/console.php`, `Schedule::call/command/job/exec` under `app/` | yes | [ScheduleScannerEndToEndTest] |
| Frequency helpers, `cron()`, minutes arguments, `daysOfMonth`, `quarterlyOn` | yes, as `cron` | [ScheduleScannerTest] |
| Sub-minute helpers | `frequency`, `cron: null` | [ScheduleScannerTest] |
| `->group(...)`, nested, variable or facade form | yes, inner modifiers win | [ScheduleScannerTest] |
| Day, time-window, `when`, `skip`, `environments`, common flags | `constraints` and booleans | [ScheduleScannerTest] |
| Macros, unknown helpers, variable arguments | `cron: null` | [ScheduleScannerTest] |
| `repeatEvery()`, `evenWhenPaused()`, ping and output hooks, closure bodies | no | [ScheduleScannerTest] |

## Routes

| Construct | Result | Test |
| --- | --- | --- |
| `Route::get/post/put/patch/delete/options/any/match` in `routes/` and loaded route files | yes; `match` is one route per verb | [RouteScannerTest] |
| Files from `loadRoutesFrom()`, `Route::group()` with a path, `withRouting(web:, api:, commands:)` | yes, for a resolvable path | [RouteFileDiscoveryTest] |
| Group prefix, name, controller, middleware, including the loading group | yes | [RouteGroupInheritanceTest] |
| Tuple, invokable, `Class@method`, closure actions | yes | [RouteScannerTest] |
| `Route::resource`, `apiResource`, `->only()`, `->except()` | yes | [RouteScannerTest] |
| Events and jobs dispatched in the controller method or closure | yes, `dispatches` | [RouteScannerEndToEndTest] |
| Middleware group and alias expansion, `withoutMiddleware` | no | [RouteChainMiddlewareTest] |
| `names()`, `parameters()`, `scoped()`, `shallow()`, `Route::resources()`, attribute routes, variable actions | no | [RouteScannerTest] |
| Computed, relative or out-of-root route file paths; non-literal group attributes | no; listed by `loom:scan -v` | [RouteFileDiscoveryTest] |

## Everywhere

- Targets must be `::class` references, `new X` expressions or string literals. Variables, concatenation and container lookups do not resolve.
- Loom walks [`scan.paths`](scan-config.md) and skips `scan.exclude`; `Events/`, `Jobs/` and the other convention directories resolve inside each scan path.
- Classes are located through the PSR-4 map in `composer.json` (`App\` to `app/` when there is none). A class that is missing or outside the scan paths is dropped.
- Files with syntax errors are skipped (`loom:scan -v` lists them), and `vendor/` is opaque: `queued` and `via()` inherited from a package do not show.
- A handler's `dispatches[]` lists events and jobs from its registered method only. Mail and notification sends appear in `mailables[].sent_from` and `notifications[].notified_from`.

[BusChainBatchEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/BusChainBatchEndToEndTest.php
[ChainModifierExtractorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/Support/ChainModifierExtractorTest.php
[ClassSpecsTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/Scanners/ClassSpecsTest.php
[ClosureDispatchAttributionPhaseTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ClosureDispatchAttributionPhaseTest.php
[ClosureOwnerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ClosureOwnerEndToEndTest.php
[ClosureOwnershipPhaseTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ClosureOwnershipPhaseTest.php
[DispatchModeEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/DispatchModeEndToEndTest.php
[DispatchRuleMatcherTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/Dispatch/DispatchRuleMatcherTest.php
[DispatchScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/DispatchScannerTest.php
[DispatchSiteVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/DispatchSiteVisitorTest.php
[DispatchesEventsVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/DispatchesEventsVisitorTest.php
[EloquentListenStringVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/EloquentListenStringVisitorTest.php
[EventDispatchSiteVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/EventDispatchSiteVisitorTest.php
[EventListenCallVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/EventListenCallVisitorTest.php
[EventScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/EventScannerTest.php
[HandlerResolutionTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/HandlerResolutionTest.php
[JobsScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/JobsScannerTest.php
[ListenArrayVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ListenArrayVisitorTest.php
[ListenerScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ListenerScannerEndToEndTest.php
[ListenerScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ListenerScannerTest.php
[MailableScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/MailableScannerEndToEndTest.php
[NotificationScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/NotificationScannerEndToEndTest.php
[NotificationScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/NotificationScannerTest.php
[ObserveCallVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ObserveCallVisitorTest.php
[ObservedByAttributeVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ObservedByAttributeVisitorTest.php
[ObserverClassVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/ObserverClassVisitorTest.php
[ObserverScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ObserverScannerTest.php
[RouteChainMiddlewareTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/RouteChainMiddlewareTest.php
[RouteFileDiscoveryTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteFileDiscoveryTest.php
[RouteGroupInheritanceTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteGroupInheritanceTest.php
[RouteScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteScannerEndToEndTest.php
[RouteScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteScannerTest.php
[ScheduleScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ScheduleScannerEndToEndTest.php
[ScheduleScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ScheduleScannerTest.php
[SubscriberClassVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/SubscriberClassVisitorTest.php
