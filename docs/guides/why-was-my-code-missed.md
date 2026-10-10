# Why was my code missed?

Loom reads source without booting the app, so anything decided at runtime is invisible to it. Find the symptom below, change the code, re-run `php artisan loom:scan` and read the section back:

```bash
jq '.unresolved_dispatches' storage/loom/index.json
```

`loom:show OrderPlaced` filters events, listeners and observers only; use `jq` or the UI for the rest. The full list of supported shapes is [What Loom detects](../reference/what-loom-detects.md).

## Events and listeners

### A listener isn't linked to its event

`handled_by` is empty, or the listener shows `handles: []`. Likely causes:

- **The handler has no usable type.** Loom follows Laravel's discovery: a public `handle*` or `__invoke` method with a first parameter, declared, inherited or from a trait. The event is read from that parameter's type (nullable and union types give one event per class). Untyped, builtin and intersection types give nothing. Write `handle(OrderPlaced $event)`.
- **The dispatcher can't be followed.** `Event::listen()`, `$this->app['events']`, `app()`, `resolve()`, `$this->app->make()` and variables assigned from them work. A dispatcher typed in `boot(Dispatcher $d)` or held in a property does not. Use the facade or the `$listen` array.
- **The registration is dynamic.** `Event::listen($name, ...)`, `'SendReceipt@handle'` strings outside `$listen`, `$obj->method(...)` and `Closure::fromCallable($var)` resolve to nothing. Use `::class` references and `[Listener::class, 'method']` tuples.
- **Registered inside a nested closure in `subscribe()`.** Loom follows `if`, `foreach` and `try`, not closures such as `->each(fn () => $events->listen(...))`.
- **Laravel would skip it too.** Abstract classes, traits, methods made non-public with `as protected`, and methods with no parameter. Handlers inherited from a vendor class can't be seen.

### A closure listener has no back-link

`handled_by` holds a class and method, which a closure lacks, so closures live in `closure_listeners` keyed by event. Dispatches inside one show in its `dispatches`, but the target's `dispatched_from` does not list the closure, and `queued` is always `false`. Move the body into a listener class to get both links.

### An event is missing from the events list

Events come from `app/Events/` and from classes passed to `event()`, `broadcast()` or `Event::dispatch()`. An event elsewhere fired only with `OrderPlaced::dispatch()` is skipped, since otherwise every `Dispatchable` job would be an event. Move it to `app/Events/` or fire it once with `event(new OrderPlaced(...))`.

### A model event handler is missing

A closure registered with `Event::listen('eloquent.created: App\Models\Order', ...)` is in `closure_listeners`, not `model_events[].handled_by`. An observer registered only through that string form is not in `observers`; use `#[ObservedBy]` or `Order::observe()`. `booting()` and `booted()` are not observable and are ignored.

## Dispatches

### A dispatch shows up as unresolved

| Reason | What Loom saw | Fix |
|---|---|---|
| `dynamic_class_name` | `event($event)` with a variable | Pass `new OrderPlaced(...)` or `OrderPlaced::class` |
| `string_concatenation` | `event("App\\Events\\" . $name)` | Use a `::class` reference |
| `container_resolution` | `event(app(OrderPlaced::class))`, `resolve(...)` | Construct it with `new` |
| `conditional_dispatch` | a ternary whose branches aren't both `new X` | Split into two `if` branches |

A ternary with `new X()` on both sides is fine; both are recorded.

### A dispatch isn't in the index at all

Skipped on purpose: code outside any class and outside a route closure; closures passed to registration APIs such as `Queue::before(...)`; first-class callables (`->each($this->notify(...))`); `Queue::pushRaw`; a dispatcher fetched from the container (`app(Dispatcher::class)->dispatch(...)`).

Dispatches inside `DB::transaction(fn () => ...)`, `each`, `tap`, `afterCommit` and closures assigned to a variable are recorded against the enclosing method. A dispatch in an inherited or trait handler is attributed to the class that declares it.

!!! warning "Dispatching from a helper method"
    A listener's `dispatches` holds only what its registered handler method dispatches. If `handle()` calls `$this->issueReceipt()` and that method fires `ReceiptIssued`, the event's `dispatched_from` has the site but the listener's `dispatches` does not. Fire from `handle()` for the link. The same holds for a job's `handle()` and an observer's hook methods.

### Dispatch options like queue or delay are missing

`overrides` records only what the chain at that line says. Not captured: a non-integer delay (`->delay(now()->addMinutes(5))`, `->delay($seconds)`), modifiers set on an earlier statement, and for notifications modifiers on the facade before `send`; put them on `(new InvoicePaid)->locale('es')`.

## Queues and jobs

### `queued` is false but my class is queued

Loom follows `extends` chains through your own code to find `implements ShouldQueue` and does not read `vendor/`, so a base class from a package reads `false`. Add `implements ShouldQueue` to your class. `ShouldBeUnique`, `ShouldBeEncrypted`, `Batchable` and `#[OnQueue]` are not reported as flags.

### `queue_config` values are null

Only class-level properties with literal values are read (`public $tries = 3;`). `backoff()`, `retryUntil()`, config lookups and constants stay `null`.

### A job is missing from the jobs list

Jobs come from `app/Jobs/` and classes passed to `dispatch(new X)`, `Bus::dispatch()`, `Bus::chain([...])` or `X::dispatch()` whose file Loom can locate. A job outside `app/Jobs/` dispatched only through a variable or an unrecognised form is not found. A class whose file can't be located through the PSR-4 map, or lies outside the [scan paths](../reference/scan-config.md), is dropped.

## Mail and notifications

### A mailable shows no senders

`Mail::raw()`, `Mail::to(...)->html(...)`, and variable sends (`Mail::send($mailable)`, listed in `unresolved_dispatches`) leave `sent_from` empty. Send `new OrderReceipt($order)` directly.

### A notification has no channels

`channels: []` with `channels_dynamic: true` means `via()` is not a single `return [...]` of string literals and `::class` constants (a conditional, property, variable or keyed entry). With `channels_dynamic: false` Loom found no `via()` on the class itself, for instance because it lives on a parent or trait; declare it on the notification. `shouldSend()`, message content and a channel filter passed as a variable are not analysed.

## Schedule

### A scheduled task is missing

Loom reads `app/Console/Kernel.php`, `->withSchedule()` in `bootstrap/app.php`, `routes/console.php` and `Schedule::` calls under the scan paths. Schedules declared elsewhere are not scanned.

### The cron value is null

A sub-minute helper (`everyFiveSeconds()`) puts the interval in `frequency` and `cron: null` is correct. Otherwise: a variable argument (`->dailyAt($time)`), a macro or unknown helper, or an unrecognised method after a frequency helper (it might change the schedule). Use a literal, a standard helper or `->cron('0 3 * * *')`. The last frequency helper wins. `->repeatEvery()` and `->evenWhenPaused()` are not captured.

### A scheduled task has a null target

An inline closure has no name (`kind: "closure"`, `target: null`), and so does a command or job given as a variable. Use `Schedule::command(SendDigest::class)` or `Schedule::job(new SendDigest)`. Dispatches inside a scheduled closure are not attributed to the task. `->when()` and `->skip()` are recorded by name only (`when(closure)`).

## Routes

### A route has no controller

A closure or variable action gives `controller_fqcn: null`. A closure route still gets the events and jobs dispatched inside it, and `end_line` marks where it ends. Use `[OrderController::class, 'show']`, an invokable class or `'OrderController@show'` for a controller target.

### Middleware shows `web`, not the classes

Middleware is recorded as written: groups and aliases are not expanded and `->withoutMiddleware()` is not subtracted. Read it as what the route declared, not what runs. Repeated `middleware()` calls follow Laravel: the last call on a group registrar chain wins, nested groups append, and calls on a route accumulate.

### Resource routes have the wrong names or URIs

`resource()` and `apiResource()` expand to Laravel's default routes. `->names()`, `->parameters()`, `->scoped()`, `->shallow()` and nested names are ignored, and `Route::resources([...])` emits nothing; call `resource()` per controller.

### A route is missing entirely

Loom reads `scan.route_paths` (default `routes/`) and route files that providers, route groups and `bootstrap/app.php` load by a path it can resolve. `php artisan loom:scan -v` lists loads it could not follow under "Route paths not followed" and non-literal group values under "Group attributes not applied". Use `__DIR__` or `base_path()` with literals, or add the directory to `scan.route_paths`. Attribute routes from a package are not found.

## When nothing shows up for a file

- **It doesn't parse.** `php artisan loom:scan -v` lists syntax errors with line and message.
- **It isn't in a scan path.** Loom scans [`scan.paths`](../reference/scan-config.md) (default `app/`), minus `scan.exclude`, plus route files and `bootstrap/app.php`. Add module directories to `scan.paths`; `vendor/` is ignored.
