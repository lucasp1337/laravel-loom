# What Loom detects

What each scanner reads and what it skips. Field meanings are in the [schema](schema.md); symptoms and fixes are in [Why was my code missed](../guides/why-was-my-code-missed.md).

Loom parses source without running your app. It reads the directories in [`scan.paths`](scan-config.md) (default `app/`), `routes/` and the route files that providers and `bootstrap/app.php` load, and `bootstrap/app.php` for the scheduler. Anything decided at runtime is out of reach.

!!! warning "Targets must be literal"
    A dispatched or registered class has to be a `::class` reference, a `new X` expression or a string literal. Variables, concatenation and container lookups do not resolve; a dispatch like that is listed in `unresolved_dispatches` instead of being linked.

Each section below lists what Loom reads, the cases that come out differently than you might expect, and what it does not read. The last line of a section names the tests that pin the behaviour.

## Events

Loom reads these as events:

- Classes in `app/Events/`, abstract included.
- Targets of `event()`, `broadcast()`, `broadcast_if()`, `broadcast_unless()` and `Event::dispatch()`, anywhere in scope.
- `X::dispatch()`, `dispatchIf()` and `dispatchUnless()`, but only for classes in `app/Events/`.
- A model's `$dispatchesEvents` map (literal hook name, `Foo::class` value), listed as dispatched by the model.

Does not read: dynamic targets (they become `unresolved_dispatches`) and anonymous classes. `ShouldBroadcast` is not reported as a flag.

An event outside `app/Events/` that is only fired with `X::dispatch()` is skipped, because the same call shape is a job elsewhere. Move the class under `app/Events/` or fire it once with `event()`.

Pinned by [EventScannerTest], [EventDispatchSiteVisitorTest], [ClassSpecsTest], [DispatchesEventsVisitorTest], [DispatchScannerTest].

## Listeners

Loom reads these as listeners:

- Public `handle*` or `__invoke` methods with a first parameter, in classes under `app/Listeners/`. The method can be declared, inherited from a parent, or come from a trait (`as` renames and `insteadof` included). Laravel's [event discovery](https://laravel.com/docs/events#event-discovery) uses the same rule.
- `$listen` arrays: bare, tuple, `Closure::fromCallable()` and `Listener::method(...)` forms.
- `Event::listen(...)` from any class, through the facade, its alias or the FQCN, with one event or an array of events.
- The same call on a dispatcher from `$this->app['events']`, `app()`, `resolve()`, `make()`, or a variable assigned from one of them.
- Subscribers, through `$subscribe` or `Event::subscribe()`, in return-array and `$events->listen()` styles. They are listed with `registration: subscriber`.

| Construct | What you get |
| --- | --- |
| Nullable or union first parameter | One event per class |
| Untyped or builtin first parameter | The listener, with `handles: []` |

Does not read: abstract classes, traits, interfaces, methods made non-public with `as protected`, methods with no parameter, handlers inherited from a vendor class, a dispatcher typed in `boot()` or held in a property, registrations inside nested closures in `subscribe()`, `$obj->method` and `'Class::method'` strings.

Pinned by [HandlerResolutionTest], [ListenerScannerTest], [ListenArrayVisitorTest], [EventListenCallVisitorTest], [SubscriberClassVisitorTest].

## Closure listeners

Loom reads these as closure listeners:

- Closures and arrow functions in `$listen`, `listen()` calls and subscribers, keyed by `::class` or a string.
- `Event::listen(function (OrderPlaced $e) {...})`, with the event taken from the first parameter's type. Union types give one entry per class.
- Events and jobs dispatched in the body, nested closures included, attributed by source span.

Does not read: a closure with an untyped, `object` or `mixed` first parameter (skipped), queue status (`queued` is always `false`), and back-links from `handled_by` and `dispatched_from`, because a closure has no class name to point at.

Pinned by [ListenerScannerEndToEndTest], [EventListenCallVisitorTest], [ClosureDispatchAttributionPhaseTest], [ClosureOwnerEndToEndTest].

## Observers

Loom reads these as observers:

- `#[ObservedBy]`, single and array form, and `Model::observe()`, `static::observe()` and `self::observe()`.
- Hook methods the observer declares, inherits from a parent or gets from a trait, of any visibility. The hooks are the events Eloquent lets an observer subscribe to, `retrieved` through `forceDeleted`, plus `trashed`. See [observers](https://laravel.com/docs/eloquent#observers).
- `Event::listen('eloquent.{hook}: {Model}', ...)` strings, as `model_events` only, with a `Class@method`, tuple or class handler. The strings `eloquent.booting: ...` and `eloquent.booted: ...` are read the same way.

Does not read: `parent::observe()`, `$this->observe()`, dynamic arguments, `booting` and `booted` methods on the observer (they are not observer hooks), hooks inherited from a vendor class, closure handlers in `model_events`, and container-form `eloquent.*` registrations.

Pinned by [ObservedByAttributeVisitorTest], [ObserveCallVisitorTest], [ObserverClassVisitorTest], [EloquentListenStringVisitorTest], [ObserverScannerTest].

## Jobs

Loom reads these as jobs:

- Classes in `app/Jobs/`, and classes passed to `dispatch(new X)`, `Bus::dispatch()` or `X::dispatch()`, including the conditional and fluent-chain forms.
- Class-level scalar `$connection`, `$queue`, `$delay`, `$tries`, `$timeout` and `$backoff`. Each is `null` when absent.

Does not read: `queued` through a vendor parent class, `backoff()` and `retryUntil()` methods, non-literal property values, and flags such as `ShouldBeUnique` and `Batchable`.

Pinned by [JobsScannerTest].

## Dispatches

Loom reads these as dispatch sites:

- `event()`, `broadcast()`, `Event::dispatch()`, `dispatch()`, `dispatch_sync()`, and `Bus::dispatch()`, `dispatchSync()`, `dispatchNow()` and `dispatchAfterResponse()`.
- `X::dispatch()`, `dispatchIf()`, `dispatchUnless()`, `dispatchSync()` and `dispatchAfterResponse()`. Whether `X` is an event or a job is decided when the index is cross-linked.
- `Bus::chain([...])` and `Bus::batch([...])`, one site per literal item.
- `Queue::push()`, `pushOn()`, `later()`, `laterOn()` and `bulk()`, and `->afterResponse()`, recorded with a `mode`.
- The chain modifiers queue, connection, integer delay, `afterCommit`, locale and mailer, recorded as `overrides`.

| Where the call sits | Counted for |
| --- | --- |
| A class method, or a closure inside it (`DB::transaction(fn () => ...)`, `each`, `tap`, a closure held in a variable) | The enclosing method |
| A closure passed to `Event::listen` or `Route::get` | That listener or route, not the enclosing method |

Does not read: `Queue::pushRaw`, closures registered with `Queue::before`, `after` or `failing`, first-class callables (`$this->send(...)`), code outside a class or a route closure, non-literal delays, and modifiers set in a separate statement.

`mode` comes from the call that was written. Loom does not evaluate `ShouldQueue` or the queue driver against it, so a `sync` driver is not reflected.

Variable, concatenated, container-resolved and conditional targets are listed in `unresolved_dispatches`. A dispatcher fetched from the container (`app(Dispatcher::class)->dispatch(...)`) is not followed.

Pinned by [DispatchRuleMatcherTest], [DispatchScannerTest], [BusChainBatchEndToEndTest], [DispatchModeEndToEndTest], [ChainModifierExtractorTest], [ClosureOwnershipPhaseTest], [DispatchSiteVisitorTest].

## Mailables and notifications

Loom reads these:

- Mailables: classes in `app/Mail/`, and classes passed to `Mail::send()`, `sendNow()`, `queue()`, `onQueue()`, `queueOn()`, `later()` and `laterOn()`, including `Mail::to()`, `cc()` and `bcc()` chains.
- Notifications: classes in `app/Notifications/`, and classes passed to `$x->notify()`, `notifyNow()`, `Notification::send()`, `sendNow()` and `Notification::route(...)->notify()`.
- Channels from a `via()` that returns a literal array, in source order, and a literal channel filter on `send`, recorded as `channels` on the site.

| Construct | What you get |
| --- | --- |
| Conditional or computed `via()` | `channels_dynamic: true` |

Does not read: `Mail::raw()`, `->html()` and `->text()` sends, an inherited `via()`, `shouldSend()`, message content, receiver types and facade modifiers before `send`. Variable targets are listed in `unresolved_dispatches`.

Pinned by [MailableScannerEndToEndTest], [NotificationScannerEndToEndTest], [NotificationScannerTest], [DispatchRuleMatcherTest].

## Schedule

Loom reads these as scheduled tasks:

- `Kernel::schedule()`, `->withSchedule()` in `bootstrap/app.php`, `routes/console.php`, and `Schedule::call()`, `command()`, `job()` and `exec()` under the scan paths.
- Frequency helpers, `cron()`, minute arguments, `daysOfMonth` and `quarterlyOn`, as `cron`.
- Sub-minute helpers, as `frequency` with `cron: null`.
- `->group(...)`, nested, in variable or facade form; the inner modifiers win.
- Day, time-window, `when`, `skip`, `environments` and the common flags, as `constraints` and booleans.

| Construct | What you get |
| --- | --- |
| Macros, unknown helpers, variable arguments | `cron: null` |

Does not read: `repeatEvery()`, `evenWhenPaused()`, ping and output hooks, and closure bodies.

Pinned by [ScheduleScannerEndToEndTest], [ScheduleScannerTest].

## Routes

Loom reads these as routes:

- `Route::get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `query()`, `any()` and `match()`, in `routes/` and in route files that are loaded. `match()` gives one route per verb.
- Chains that start with attributes: `Route::middleware('auth')->prefix('v1')->name('v1.')->get(...)`, also before `match()`, `any()` and the resource methods.
- `Route::view()` (`GET`), `redirect()` and `permanentRedirect()` (`ANY`) and `fallback()` (`GET`, URI `/{fallbackPlaceholder}`). They carry `kind` `view`, `redirect` or `fallback`.
- Route files loaded by `loadRoutesFrom()`, `Route::group()` with a path, `withRouting(web:, api:, commands:)`, or a `require` / `include` inside a route group or at the top of a route file, when the path is a literal, `__DIR__`, `__FILE__`, `DIRECTORY_SEPARATOR`, `dirname()`, `base_path()`, `app_path()` or a concatenation of these.
- Groups with prefix, name, controller, middleware and `withoutMiddleware`, including the group that loads a route file. `withRouting()` loads `web` under the `web` middleware and `api` under the `api` middleware with the `api` prefix.
- Tuple, invokable, `Class@method` and closure actions.
- `Route::resource()`, `apiResource()`, `singleton()`, `apiSingleton()` and the array forms `resources()`, `apiResources()`, `softDeletableResources()`, `singletons()`, `apiSingletons()`. Names, URIs and verbs follow Laravel: `update` is `PUT` and `PATCH`, nested `a.b` gives `a/{a}/b/{b}`, wildcards are singular with `-` as `_`, and `admin/photos` prefixes the URI only. Options are read from the literal options array and from `->only()`, `->except()`, `->names()`, `->name()`, `->parameters()`, `->parameter()`, `->shallow()`, `->creatable()`, `->destroyable()`, `->middleware()`, `->middlewareFor()`, `->withoutMiddleware()` and `->withoutMiddlewareFor()`.
- Router macros named in [`scan.route_macros`](scan-config.md), read as the router method they behave like.
- Events and jobs dispatched in the controller method, or in the closure of a closure route, as `dispatches`.

Group middleware is what the chain ends up with: a second `->middleware()` on a group chain replaces the first, nested groups add the inner list after the outer one, and `->middleware()` calls on a single route accumulate. `withoutMiddleware` removes entries by the name as written, after all lists are joined. `Class::class.':arg'` is read as `Class:arg`. See [route groups](https://laravel.com/docs/routing#route-group-middleware).

Not read, and listed in `unresolved_routes` instead: `Route::<name>()` calls that are not router methods (macros, package helpers), routes whose URI, resource name or `match()` verbs are not literals, resource options that are not literals (the defaults are used), middleware entries that are not literals, non-literal group attributes, route files loaded from a computed, relative or out-of-root path, and controllers that declare routes with attributes (`#[Get]`, `#[Resource]` and the like). Attribute routes are not read: Laravel has no route attributes of its own, so each one is reported with its source.

Does not read: middleware group and alias expansion, `scoped()`, variable actions, and the loading group's `domain`, `where` and `namespace`. A `prefix` on a chain that ends in a resource call is ignored, as Laravel ignores it.

`app_path()` is taken as `app/`; a `useAppPath()` override is a runtime change Loom cannot see.

Pinned by [RouteScannerTest], [RouteFileDiscoveryTest], [RouteGroupInheritanceTest], [RouteChainMiddlewareTest], [RouteScannerEndToEndTest], [RouteFormsTest].

## Everywhere

- [`scan.paths`](scan-config.md) are walked and `scan.exclude` is skipped. `Events/`, `Jobs/` and the other convention directories resolve inside each scan path.
- Classes are located through the PSR-4 map in `composer.json` (`App\` to `app/` when there is none). A class that is missing or outside the scan paths is dropped.
- Files with syntax errors are skipped; `loom:scan` prints their count and `-v` lists them.
- `vendor/` is opaque, so `queued` and `via()` inherited from a package do not show.
- A handler's `dispatches[]` lists events and jobs from its registered method only: a dispatch inside a private helper that `handle()` calls is attributed to the event's `dispatched_from` but not to the listener, job or observer. Routes attribute by controller method. Mail and notification sends appear in `mailables[].sent_from` and `notifications[].notified_from` instead.

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
[RouteFormsTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteFormsTest.php
[RouteFileDiscoveryTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteFileDiscoveryTest.php
[RouteGroupInheritanceTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteGroupInheritanceTest.php
[RouteScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteScannerEndToEndTest.php
[RouteScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/RouteScannerTest.php
[ScheduleScannerEndToEndTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ScheduleScannerEndToEndTest.php
[ScheduleScannerTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Feature/ScheduleScannerTest.php
[SubscriberClassVisitorTest]: https://github.com/lucasp1337/laravel-loom/blob/main/tests/Unit/SubscriberClassVisitorTest.php
