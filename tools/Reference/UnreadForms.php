<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Lucasp\Loom\Index\UnresolvedReason as Reason;

/**
 * The example behind every unresolved reason: the code shape and the change
 * that makes Loom read it. `UnreadFormsTest` scans each one and fails when it
 * does not come out under its reason, so the page cannot drift from the scanners.
 */
final class UnreadForms
{
    /** @return list<UnreadForm> in page order: enum order, then declaration order */
    public static function all(): array
    {
        return array_merge(...array_map(self::for(...), Reason::cases()));
    }

    /** @return list<UnreadForm> */
    public static function for(Reason $reason): array
    {
        return array_values(array_filter(self::definitions(), static fn (UnreadForm $form): bool => $form->reason === $reason));
    }

    /** @return list<UnreadForm> */
    private static function definitions(): array
    {
        return [
            new UnreadForm(
                Reason::DYNAMIC_CLASS_NAME,
                FormContext::Method,
                'event($event);',
                'Pass `new OrderPlaced(...)` or `OrderPlaced::class` at the call. A variable the method assigns once with `new X` or `X::class` is followed.',
            ),
            new UnreadForm(
                Reason::DYNAMIC_CLASS_NAME,
                FormContext::Method,
                '$class::dispatch($id);',
                'Call the class itself: `ProcessOrder::dispatch($id)`.',
            ),
            new UnreadForm(
                Reason::CONTAINER_RESOLUTION,
                FormContext::Method,
                'event(app(OrderPlaced::class));',
                'Construct the event with `new`.',
                ['App\Events\OrderPlaced'],
            ),
            new UnreadForm(
                Reason::STRING_CONCATENATION,
                FormContext::Method,
                <<<'PHP'
                event('App\Events\\'.$name);
                PHP,
                'Use a `::class` reference.',
            ),
            new UnreadForm(
                Reason::CONDITIONAL_DISPATCH,
                FormContext::Method,
                'event($urgent ? new OrderEscalated : $fallback);',
                'Make both branches `new X`, which Loom records, or split into two `if` branches that each dispatch one class.',
                ['App\Events\OrderEscalated'],
            ),
            new UnreadForm(
                Reason::DYNAMIC_LIST,
                FormContext::Method,
                'Bus::chain($jobs)->dispatch();',
                'Write the list as an array literal of `new X` items. A list the method assigns once is followed.',
            ),
            new UnreadForm(
                Reason::DYNAMIC_LIST,
                FormContext::Method,
                'Bus::chain([new Prepare, ...$jobs])->dispatch();',
                'Spell every job out in the literal; the `new X` items beside the spread are still read.',
                ['App\Jobs\Prepare'],
                ['app/Jobs/Prepare.php' => "<?php\n\nnamespace App\\Jobs;\n\nfinal class Prepare {}\n"],
            ),
            new UnreadForm(
                Reason::STRING_EVENT_NAME,
                FormContext::Method,
                "event('service.installed');",
                'Fire an event class, `event(new ServiceInstalled(...))`. Loom indexes class events, so a string name is not linked to anything.',
            ),
            new UnreadForm(
                Reason::CLOSURE_JOB,
                FormContext::Method,
                'dispatch(function () { /* ... */ });',
                'Move the work into a job class and dispatch `new SyncInventory(...)`.',
            ),
            new UnreadForm(
                Reason::VIEW_MAIL,
                FormContext::Method,
                "Mail::send('emails.welcome', \$data, fn (\$message) => \$message->to(\$user));",
                'Send a mailable: `Mail::to($user)->send(new Welcome($data))`.',
            ),
            new UnreadForm(
                Reason::CALL_RESULT,
                FormContext::Method,
                'event($this->makeEvent());',
                'Construct the event where it is dispatched: `event(new OrderPlaced(...))`.',
            ),
            new UnreadForm(
                Reason::STORED_TARGET,
                FormContext::Method,
                'event($this->event);',
                'Construct the event where it is dispatched: `event(new OrderPlaced(...))`.',
            ),
            new UnreadForm(
                Reason::RELATIVE_CLASS_NAME,
                FormContext::TraitMethod,
                'self::dispatch($id);',
                'Name the class: `ProcessOrder::dispatch($id)`. In a trait, `self` is whichever class uses it, and Loom does not follow that.',
            ),
            new UnreadForm(
                Reason::UNSUPPORTED_EXPRESSION,
                FormContext::Method,
                'event($event ?? new OrderPlaced);',
                'Dispatch a `new X` or `X::class` directly.',
                ['App\Events\OrderPlaced'],
            ),
            new UnreadForm(
                Reason::CLASS_NOT_FOUND,
                FormContext::Method,
                'event(new Registered($user));',
                'Nothing to fix for a package class. For one of your own, add its directory to `scan.paths` or correct the name.',
                ['Illuminate\Auth\Events\Registered'],
            ),
            new UnreadForm(
                Reason::TARGET_NOT_INDEXED,
                FormContext::Method,
                'StartLogDrain::dispatch();',
                'Make the class a job (`ShouldQueue`, or place it under `Jobs/`) or an event so it gets a row.',
                ['App\Support\StartLogDrain'],
                ['app/Support/StartLogDrain.php' => "<?php\n\nnamespace App\\Support;\n\nfinal class StartLogDrain\n{\n    public static function dispatch(): void {}\n}\n"],
            ),
            new UnreadForm(
                Reason::UNREADABLE_EVENT_NAME,
                FormContext::Method,
                'Event::listen($name, SendReceipt::class);',
                'Register against `OrderPlaced::class` or a string literal.',
                ['App\Listeners\SendReceipt'],
            ),
            new UnreadForm(
                Reason::UNTYPED_CLOSURE_LISTENER,
                FormContext::Method,
                'Event::listen(function ($event) { /* ... */ });',
                'Type the first parameter with the event class: `function (OrderPlaced $event)`.',
            ),
            new UnreadForm(
                Reason::UNREADABLE_LISTENER,
                FormContext::Method,
                'Event::listen(OrderPlaced::class, $listener);',
                'Pass `SendReceipt::class`, a `[SendReceipt::class, \'handle\']` tuple or a closure.',
                ['App\Events\OrderPlaced'],
            ),
            new UnreadForm(
                Reason::UNREADABLE_SUBSCRIBER,
                FormContext::Method,
                'Event::subscribe($subscriber);',
                'Pass `OrderSubscriber::class`.',
            ),
            new UnreadForm(
                Reason::UNREADABLE_MODEL_HOOK,
                FormContext::ModelBooted,
                'static::creating($callback);',
                'Pass a closure, or a `[Handler::class, \'method\']` callable.',
            ),
        ];
    }
}
