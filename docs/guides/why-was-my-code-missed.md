# Why was my code missed?

Loom reads your source without booting the app, so anything decided at runtime is invisible to it. This page starts from what you see in the index or the UI and works back to the cause. Each entry says why it happens, what to change, and how to confirm the fix.

To confirm any fix, re-run the scan and read the section you care about:

```bash
php artisan loom:scan
jq '.unresolved_dispatches' storage/loom/index.json
```

`loom:show OrderPlaced` is handy for events, listeners and observers, but it only filters those sections. Jobs, mailables, notifications, schedules and routes always print in full, so use `jq` or the UI for them.

For a compact list of what each primitive supports, see [What Loom detects](../reference/what-loom-detects.md).

## Events and listeners

### A listener isn't linked to its event

`OrderPlaced` shows an empty `handled_by`, or the listener shows `handles: []`. Pick the shape that matches your code.

**The `handle()` parameter has no usable type.** Loom reads the event from the first parameter's type. An untyped parameter, a union (`OrderPlaced|OrderUpdated`), a nullable (`?OrderPlaced`), an intersection or a builtin type gives it nothing to read. Type the parameter with one event class:

```php
public function handle(OrderPlaced $event): void
```

**The registration is on a dispatcher Loom can't follow.** Loom recognizes `Event::listen(...)`, `$this->app['events']->listen(...)`, and a dispatcher fetched with `app()`, `resolve()` or `$this->app->make()`, including a local variable assigned from one of those. It does not recognize a dispatcher that arrives as a typed `boot(Dispatcher $dispatcher)` parameter or sits in a property (`$this->dispatcher->listen(...)`). Use the facade, or register the listener in the `$listen` array of your `EventServiceProvider`:

```php
protected $listen = [
    OrderPlaced::class => [SendOrderConfirmation::class],
];
```

**The event name or listener is dynamic.** `Event::listen($name, ...)`, string callables like `'SendReceipt@handle'` outside `$listen`, `$obj->method(...)` and `Closure::fromCallable($var)` resolve to nothing. Use `::class` references and `[Listener::class, 'method']` tuples.

**The listener registers itself inside a nested closure.** In a subscriber's `subscribe()` method, Loom follows `if`, `foreach` and `try` blocks but not closures inside them, so `collect([...])->each(fn () => $events->listen(...))` is invisible. Register with plain `$events->listen(...)` calls or return an array.

**Confirm:** `php artisan loom:show OrderPlaced` lists the listener under `handled_by`.

### A closure listener has no back-link

You registered `Event::listen(OrderPlaced::class, fn ($e) => ...)` and `OrderPlaced` shows nothing in `handled_by`. `handled_by` holds a class and method name, and a closure has neither. Closure registrations live in their own `closure_listeners` section, keyed by event.

**Workaround:** to answer "what handles `OrderPlaced`?", read both `handled_by` and the closure entries:

```bash
jq '.closure_listeners[] | select(.event == "App\\Events\\OrderPlaced")' storage/loom/index.json
```

If you want the link in `handled_by`, move the closure body into a listener class and register it by name. The same applies in reverse: an event or job dispatched from inside a closure listener appears in that closure's `dispatches`, but the target's `dispatched_from` doesn't list the closure.

Closure listeners always show `queued: false`, even if you wrap the work in a queued call.

### An event is missing from the events list

Loom finds events in `app/Events/`, plus any class passed to `event(...)`, `broadcast(...)` or `Event::dispatch(...)`. An event elsewhere that you only fire with `OrderPlaced::dispatch()` is skipped, because otherwise every job using the `Dispatchable` trait would be listed as an event.

**Fix:** move the class into `app/Events/`, or fire it once with `event(new OrderPlaced($order))`.

**Confirm:** `jq '.events[].fqcn' storage/loom/index.json` includes it.

### A model event handler is missing

`Event::listen('eloquent.created: App\Models\Order', ...)` with a closure doesn't add anything to `model_events[].handled_by`, which only holds `Observer::method` names. The closure is in `closure_listeners` with the raw event string. An observer that only registers through this string form also never appears in `observers`. Register it with `#[ObservedBy(OrderObserver::class)]` or `Order::observe(OrderObserver::class)` instead.

Hook methods inherited from a parent observer or provided by a trait aren't seen either. Declare the hook method on the observer class itself.

## Dispatches

### A dispatch shows up as unresolved

Entries in `unresolved_dispatches` carry a `reason`. Each one points at the line to change:

| Reason | What Loom saw | Fix |
|---|---|---|
| `dynamic_class_name` | `event($event)` where `$event` is a variable | Pass `new OrderPlaced(...)` or `OrderPlaced::class` at the call |
| `string_concatenation` | `event("App\\Events\\" . $name)` | Use a `::class` reference |
| `container_resolution` | `event(app(OrderPlaced::class))` or `resolve(...)` | Construct the event with `new` |
| `conditional_dispatch` | a ternary whose branches aren't both `new X` | Split into two `if` branches, each with its own `event(new ...)` |

A ternary where both branches are `new X()` is fine. Loom records both.

**Confirm:** the entry disappears from `jq '.unresolved_dispatches' storage/loom/index.json`, and the event's `dispatched_from` gains the site.

### A dispatch isn't in the index at all

Loom skips these on purpose:

- Anything inside a closure or arrow function, such as `collect($orders)->each(fn ($o) => event(new OrderPlaced($o)))`. The closure may never run, so Loom won't claim it does. Move the dispatch into a named method.
- Code outside any class, such as script-level statements.
- `dispatchSync`, `dispatchNow`, `dispatchAfterResponse`, `Queue::push` and `Queue::later`.
- `Bus::chain([...])` and `Bus::batch([...])`. The jobs inside never show a `dispatched_from`.
- A dispatcher fetched from the container: `app(Dispatcher::class)->dispatch(...)`.

Closure-internal dispatches with dynamic targets also don't reach `unresolved_dispatches`, so there's no warning for them either.

!!! warning "A listener that dispatches from a helper method"
    A listener's `dispatches` only includes dispatches made inside the method registered as its handler. If `handle()` calls `$this->issueReceipt()` and that private method fires `ReceiptIssued`, the dispatch still counts toward `ReceiptIssued`'s `dispatched_from`, but it won't appear in `SendReceipt`'s `dispatches`. Fire the event from `handle()` itself for the link to show. The same rule applies to jobs (`handle()`) and observers (the hook methods).

### Dispatch options like queue or delay are missing

A dispatch site's `overrides` records only what the chain at that line says: `->onQueue('high')`, `->onConnection('redis')`, `->delay(60)`, `->afterCommit()`, and for mail `->locale()`/`->mailer()`. These are not captured:

- A delay that isn't an integer literal: `->delay(now()->addMinutes(5))` or `->delay($seconds)`.
- Modifiers set on an earlier statement: `$job->onQueue('high'); dispatch($job);`.
- For notifications, modifiers on the facade before `send`: `Notification::locale('es')->send(...)`. Put them on the notification instance instead: `(new InvoicePaid)->locale('es')`.

A dispatch whose target is a variable (`dispatch($job)`) can't be resolved at all. Pass `new ProcessOrder(...)` directly.

## Queues and jobs

### `queued` is false but my class is queued

Loom decides `queued` by following `extends` chains through your own `app/` code to find `implements ShouldQueue`. It doesn't look inside `vendor/`, so a job, mailable, notification or listener that gets `ShouldQueue` from a package's base class reads `false`.

**Fix:** add `implements ShouldQueue` directly to your class. It's redundant at runtime and makes the intent visible.

**Confirm:** `jq '.jobs[] | select(.fqcn | endswith("SendReceipt")) | .queued' storage/loom/index.json` prints `true`.

The same applies to `ShouldBeUnique`, `ShouldBeEncrypted`, `Batchable` and `#[OnQueue]`, which Loom doesn't report as flags at all.

### `queue_config` values are null

Loom reads class-level properties with literal values, such as `public $tries = 3;` or `public $queue = 'emails';`. It doesn't run `backoff()` or `retryUntil()` methods, and a property set from `config('queue.default')` or a constant stays `null`. Use a literal property if you want the value in the index.

### A job is missing from the jobs list

Loom finds jobs in `app/Jobs/` and any class passed to `dispatch(new X)`, `Bus::dispatch(...)` or `X::dispatch()` whose file it can locate. It skips abstract classes, interfaces and traits. A job outside `app/Jobs/` that's only dispatched through `dispatchSync` or `Bus::chain` isn't found. Move it under `app/Jobs/`.

A class whose file can't be located is dropped, since every entry needs a file and line. Loom maps a leading `App\` to `app/`, so a project with a different root namespace won't match.

## Mail and notifications

### A mailable shows no senders

`sent_from` stays empty when the send happens through `Mail::raw(...)`, `Mail::to(...)->html(...)`, inside a closure, or with a variable (`Mail::send($mailable)`). Variable sends appear in `unresolved_dispatches`; the raw forms have no class to index and don't appear anywhere. Send `new OrderReceipt($order)` directly:

```php
Mail::to($order->customer)->send(new OrderReceipt($order));
```

### A notification has no channels

`channels: []` with `channels_dynamic: true` means `via()` exists but isn't a single `return [...]` of string literals and `::class` constants. A conditional, a property, a variable or keyed entries all set the flag. Loom doesn't merge branches, so it shows the gap instead of guessing.

`channels: []` with `channels_dynamic: false` means Loom found no `via()` on the class itself. If `via()` lives on a parent class or in a trait, that's why. Declare it on the notification:

```php
public function via(object $notifiable): array
{
    return ['mail', 'database'];
}
```

`shouldSend()` gating and the content of `toMail()` and `toSlack()` are never analyzed. A channel filter passed as a variable, such as `Notification::send($users, $n, $channels)`, is left out.

### I can't see which models receive a notification

`$user->notify(new InvoicePaid)` records the notification but not the type of `$user`. The index can't answer "which models get `InvoicePaid`".

## Schedule

### A scheduled task is missing

Loom reads the scheduler from `app/Console/Kernel.php`, `->withSchedule(...)` in `bootstrap/app.php`, `routes/console.php`, and `Schedule::` calls in any file under `app/`. Schedules declared anywhere else (for example a package or a custom directory) are not scanned.

**Confirm:** `jq '.scheduled[] | {target, cron}' storage/loom/index.json` lists the task.

### The cron value is null

`cron: null` means Loom saw the task but couldn't turn its frequency into a cron string. Check these in order:

1. **Sub-minute helper.** `everyFiveSeconds()` and the other `every...Seconds()` helpers don't fit a five-field cron. The interval is in `frequency`, and `cron: null` is correct.
2. **Variable argument.** `->dailyAt($time)` has no literal to read. Use a literal, or `->cron('0 3 * * *')`.
3. **Macro or unknown helper.** A `Schedule::macro('myEvery5', ...)` call or a helper Loom doesn't know produces `null`. A method Loom doesn't recognize after a frequency helper also clears it, because the method might change the schedule. Use a standard helper or `->cron(...)`.
4. **Several frequency helpers.** Last one wins, and if the last is unrecognized the whole value is `null`.

`->repeatEvery($seconds)` and `->evenWhenPaused()` aren't captured. Use the named sub-minute helpers.

### A scheduled task has a null target

Inline closures have no name, so `->call(fn () => ...)` shows `kind: "closure"` and `target: null`. A command or job given as a variable or computed expression also gets `null`. Use `Schedule::command(SendDigest::class)` or `Schedule::job(new SendDigest)`.

A `->job(...)` for a class in `vendor/` gives a valid target with no matching row in `jobs`. Jobs dispatched inside a scheduled closure don't appear either, since closures are skipped.

`->when(fn () => ...)` and `->skip(...)` are recorded by name only, as `when(closure)`. Loom doesn't read the closure body.

## Routes

### A route has no controller

`controller_fqcn` and `controller_method` are `null` when the action is a closure (`fn () => ...`) or a variable. Loom won't guess. Use `[OrderController::class, 'show']`, an invokable `OrderController::class`, or the `'OrderController@show'` string.

### Middleware shows `web` or `auth`, not the classes

Middleware is recorded as written. Groups (`web`, `api`) and aliases (`auth`, `throttle`) aren't expanded to classes, and `->withoutMiddleware(...)` isn't subtracted. Read the value as "what this route declared", not "what runs".

### Resource routes have the wrong names or URIs

`Route::resource()` and `Route::apiResource()` expand to Laravel's default routes and names. These are ignored: `->names(...)`, `->parameters(...)`, `->scoped(...)`, `->shallow()`, and nested names like `photos.comments` (treated as one flat name). `Route::resources([...])` and `Route::apiResources([...])` emit nothing, so call `resource()` per controller.

### A route is missing entirely

Loom reads `*.php` files under `routes/` and looks for `Route::` facade calls. Routes defined by attributes from a package such as `spatie/laravel-route-attributes` aren't found.

## When nothing shows up for a file

Two checks apply to every primitive.

**The file doesn't parse.** Loom skips files with syntax errors silently. Run `php -l app/Listeners/SendReceipt.php`, fix it and scan again.

**The code isn't under `app/`.** Loom scans `app/` (and `routes/` for routes, `bootstrap/app.php` for the scheduler). Vendor packages and modules kept elsewhere are ignored.
