# What Loom detects

What each scanner reads and what it skips. Field meanings are in the [schema](schema.md); symptoms and fixes are in [Why was my code missed](../guides/why-was-my-code-missed.md).

Loom parses source without running your app. It reads the directories in [`scan.paths`](scan-config.md) (default `app/`), `routes/` and the route files that providers and `bootstrap/app.php` load, and `bootstrap/app.php` for the scheduler. Anything decided at runtime is out of reach.

!!! warning "Targets must be literal"
    A dispatched or registered class has to be a `::class` reference, a `new X` expression or a string literal. Variables, concatenation and container lookups do not resolve; a dispatch like that is listed in `unresolved_dispatches` instead of being linked. [Forms Loom does not read](unread-forms.md) shows each such form as code, with the reason it gets and the fix.

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

- Public `handle*` or `__invoke` methods with a first parameter, in classes under `app/Listeners/` (or the directories passed to `withEvents([...])`). The method can be declared, inherited from a parent, or come from a trait (`as` renames and `insteadof` included). Laravel's [event discovery](https://laravel.com/docs/events#event-discovery) uses the same rule. They are `registration: auto_discovered` only when `bootstrap/app.php` uses `Application::configure()` and nothing turns discovery off. With `withEvents(false)` or `disableEventDiscovery()` such a class is listed only if registered explicitly. When the switch can't be read (no `bootstrap/app.php`, a non-literal `withEvents(...)` argument) the class is listed as `discovery_unverified`. A pre-`configure()` bootstrap discovers only when an app provider's `shouldDiscoverEvents()` returns `true`.
- `$listen` arrays: bare, tuple, `'Fqcn@method'`, `Listener::class.'@method'`, `Closure::fromCallable()` and `Listener::method(...)` forms, with a class or literal string event key (a dotted name or wildcard is kept as written).
- `Event::listen(...)` from any class, through the facade, its alias or the FQCN, with one event or an array of events.
- The same call on a dispatcher from `$this->app['events']`, `app()`, `resolve()`, `make()`, or a variable assigned from one of them.
- Subscribers, through `$subscribe` or `Event::subscribe()`, in return-array and `$events->listen()` styles. They are listed with `registration: subscriber`.

| Construct | What you get |
| --- | --- |
| Nullable or union first parameter | One event per class |
| Untyped or builtin first parameter | The listener, with `handles: []` |

Does not read: abstract classes, traits, interfaces, methods made non-public with `as protected`, methods with no parameter, handlers inherited from a vendor class, a dispatcher typed in `boot()` or held in a property, registrations inside nested closures in `subscribe()`, `$obj->method` and `'Class::method'` strings. A registration whose event or listener is one of these, a variable or a call (`Event::listen($name, ...)`, `[$this, 'handle']`) is listed in `unresolved_dispatches` with a reason (`unreadable_event_name`, `unreadable_listener`, `unreadable_subscriber` or `untyped_closure_listener`, see [Forms Loom does not read](unread-forms.md#listener-registrations)). A listener is matched to the event class it is typed to, not to that class's parents.

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

- Model hook registrations `static::creating(...)`, `saved`, `deleted`, `softDeleted` and the other registrar methods, from `booted()`, `boot()`, a trait's `bootXxx()` or outside the model. A closure or arrow function becomes a `model_events` handler with method `{closure}` and a `closure_listeners` entry (`registration: model_hook`) that holds the dispatches inside it. A `[Handler::class, 'method']`, `Handler::class` or `'Handler@method'` callback becomes a handler. A hook in a trait is listed once for each class that uses the trait.
- `eloquent.{hook}: {Model}` keys in `$listen`, and closure handlers in `Event::listen('eloquent.*', fn ...)`, as `model_events`.

Does not read: `parent::observe()`, `$this->observe()`, dynamic arguments, `booting` and `booted` methods on the observer (they are not observer hooks), hooks inherited from a vendor class, `$observables` custom events, and container-form `eloquent.*` registrations. A `static::creating($callback)` whose callback is a variable or call is listed in `unresolved_dispatches` with reason `unreadable_model_hook` (see [model hooks](unread-forms.md#model-hooks)). `Model::creating($variable)` outside the model is skipped, because the class isn't known to be a model.

Pinned by [ObservedByAttributeVisitorTest], [ObserveCallVisitorTest], [ObserverClassVisitorTest], [EloquentListenStringVisitorTest], [ObserverScannerTest].

## Jobs

Loom reads these as jobs:

- Classes in `app/Jobs/`, and classes passed to `dispatch(new X)`, `Bus::dispatch()` or `X::dispatch()`, including the conditional and fluent-chain forms.
- Class-level scalar `$connection`, `$queue`, `$delay`, `$tries`, `$timeout` and `$backoff`, and the `$afterCommit` flag, inherited from parent classes and traits (most derived wins). Each is `null` when absent.
- Literal `$this->onQueue('x')`, `onConnection('x')`, `delay(60)`, `afterCommit()`, `beforeCommit()` and `$this->queue = 'x'` statements at the top level of the constructor. They win over property defaults; a parent's constructor counts only while the child calls `parent::__construct()`.
- `queued: true` for `ShouldQueue` and `ShouldQueueAfterCommit`, directly or through parents and your own interfaces.
- `queue_config.after_commit`: `true` for `ShouldQueueAfterCommit`, a literal `$afterCommit = true` or an `afterCommit()` call; `false` for a literal `false` or `beforeCommit()`; `null` when the class says nothing, because the queue connection's `after_commit` setting decides. A job can opt out of `ShouldQueueAfterCommit` with `false`; a mailable or notification cannot.

Does not read: `queued` through a vendor parent class, constructor calls inside a branch, `backoff()` and `retryUntil()` methods, non-literal property values, and flags such as `ShouldBeUnique` and `Batchable`.

Pinned by [JobsScannerTest].

## Dispatches

Loom reads these as dispatch sites:

- `event()`, `broadcast()`, `Event::dispatch()`, `dispatch()`, `dispatch_sync()`, and `Bus::dispatch()`, `dispatchSync()`, `dispatchNow()` and `dispatchAfterResponse()`.
- `X::dispatch()`, `dispatchIf()`, `dispatchUnless()`, `dispatchSync()` and `dispatchAfterResponse()`. Whether `X` is an event or a job is decided when the index is cross-linked.
- `Bus::chain([...])` and `Bus::batch([...])`, one site per literal item. A nested array (a chain inside a batch) is read item by item; a list held in a variable that is assigned once is read too.
- `self::`, `static::` and `parent::` forms (`self::dispatch()`, `new self`), resolved to the class that contains the call.
- Nullsafe calls such as `$team?->notify(new BackupFailed)`.
- A target held in a local variable that the method assigns exactly once with `new X`, `X::class` or a ternary of those. A variable that is a parameter, assigned twice, or changed any other way stays unresolved.
- `Queue::push()`, `pushOn()`, `later()`, `laterOn()` and `bulk()`, and `->afterResponse()`, recorded with a `mode`.
- The chain modifiers queue, connection, integer delay, `afterCommit`, locale and mailer, recorded as `overrides`.

| Where the call sits | Counted for |
| --- | --- |
| A class method, or a closure inside it (`DB::transaction(fn () => ...)`, `each`, `tap`, a closure held in a variable) | The enclosing method |
| A closure passed to `Event::listen` or `Route::get` | That listener or route, not the enclosing method |

Does not read: `Queue::pushRaw`, closures registered with `Queue::before`, `after` or `failing`, first-class callables (`$this->send(...)`), code outside a class or a route closure, non-literal delays, and modifiers set in a separate statement.

`mode` comes from the call that was written. Loom does not evaluate `ShouldQueue` or the queue driver against it, so a `sync` driver is not reflected.

Variable, concatenated, container-resolved and conditional targets, string event names, closure jobs, view-based mail, `$class::dispatch()`, and job lists built at runtime are listed in `unresolved_dispatches`, each with its own reason (see [dispatch targets](unread-forms.md#dispatch-targets)). So is a target class that has no row in the index (an action class that is not a job, an event from a package): it is never linked. A dispatcher fetched from the container (`app(Dispatcher::class)->dispatch(...)`) is not followed.

Pinned by [DispatchRuleMatcherTest], [DispatchScannerTest], [BusChainBatchEndToEndTest], [DispatchModeEndToEndTest], [ChainModifierExtractorTest], [ClosureOwnershipPhaseTest], [DispatchSiteVisitorTest].

## Mailables and notifications

Loom reads these:

- Mailables: classes in `app/Mail/` that extend `Mailable` (or a class outside your code) or declare `build()`, `envelope()` or `content()`, and classes passed to `Mail::send()`, `sendNow()`, `queue()`, `onQueue()`, `queueOn()`, `later()` and `laterOn()`, including `Mail::to()`, `cc()` and `bcc()` chains.
- Notifications: classes in `app/Notifications/` that extend `Notification` (or a class outside your code) or declare `via()`, and classes passed to `$x->notify()`, `notifyNow()`, `Notification::send()`, `sendNow()` and `Notification::route(...)->notify()`.
- Channels from a `via()` that returns a literal array, or a local array built from a literal with `$x[] = 'literal'` appends, in source order. Appends or returns under a branch give the union of the possible channels with `channels_dynamic: true`. and a literal channel filter on `send`, recorded as `channels` on the site.

| Construct | What you get |
| --- | --- |
| Conditional `via()` | the union of possible channels, `channels_dynamic: true` |
| Computed `via()` (call, property, keyed or non-literal item) | `channels: []`, `channels_dynamic: true` |

Does not read: `Mail::raw()`, `->html()` and `->text()` sends, an inherited `via()`, `shouldSend()`, message content, receiver types and facade modifiers before `send`. Variable targets and view-based `Mail::send('view', ...)` are listed in `unresolved_dispatches`, and so is a dispatched class whose file Loom cannot find (a package class, a missing file, an excluded path) with reason `class_not_found`.

Pinned by [MailableScannerEndToEndTest], [NotificationScannerEndToEndTest], [NotificationScannerTest], [DispatchRuleMatcherTest].

## Schedule

Loom reads these as scheduled tasks:

- `Kernel::schedule()`, `->withSchedule()` in `bootstrap/app.php`, `routes/console.php`, and `Schedule::call()`, `command()`, `job()` and `exec()` under the scan paths.
- Frequency helpers, `cron()`, minute arguments, `daysOfMonth` and `quarterlyOn`, as `cron`.
- Sub-minute helpers, as `frequency` with `cron: null`.
- `->group(...)`, nested, in variable or facade form; the inner modifiers win.
- A `Schedule` property or a `Schedule` parameter of a method that `schedule()` calls on `$this`, and `Artisan::command(...)->hourly()` (the command name is the signature's first word).
- `->at('3:15')` as `dailyAt`, keeping the day fields of an earlier `weekly()` or `monthly()`.
- Enclosing `if`, `elseif` and `else` blocks, as `if(<condition>)`, `elseif(<condition>)` and `else(<if condition>)` constraints.
- Day, time-window, `when`, `skip`, `environments` and the common flags, as `constraints` and booleans.

| Construct | What you get |
| --- | --- |
| Macros, unknown helpers, variable arguments | `cron: null` |

A `schedule()` body whose tasks cannot be read, or a `Schedule` helper that `schedule()` never calls, is listed under skipped files (`loom:scan -v`).

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
- Classes are located through the PSR-4 map in `composer.json` (`App\` to `app/` when there is none), plus the files in `extra.merge-plugin.include` and `scan.composer_files`. A class that is missing or outside the scan paths is dropped.
- Files with syntax errors are skipped; `loom:scan` prints their count and `-v` lists them.
- `vendor/` is opaque, so `queued` and `via()` inherited from a package do not show.
- A handler's `dispatches[]` lists events, jobs, mailables and notifications dispatched from its registered method, and from helper methods of the same class that it reaches through `$this->`, `self::` or `static::` calls (up to three levels). A dispatch reached that way carries `via`, the helper names in call order, and `confidence: medium`. Helpers inherited from a parent class or trait, and calls on other objects, are not followed. Routes attribute the same way from the controller method.

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
