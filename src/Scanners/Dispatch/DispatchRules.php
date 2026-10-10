<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Dispatch;

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Support\Facades;

/**
 * The dispatch forms Loom recognises, one row per call shape. Adding a dispatch
 * form is adding a row. Order matters only within METHOD_CALL rows (first match
 * wins), so the facade-rooted rows come before their any-receiver fallbacks.
 *
 * @internal
 */
final class DispatchRules
{
    /** Static `Mail::` methods that start a PendingMail chain. */
    private const MAIL_ROOT_METHODS = ['to', 'cc', 'bcc', 'locale', 'mailer'];

    /** @var list<DispatchRule>|null */
    private static ?array $all = null;

    /**
     * The table `DispatchSiteVisitor` evaluates.
     *
     * @return list<DispatchRule>
     */
    public static function all(): array
    {
        return self::$all ??= [
            // `event($e)`
            self::helperFunction('event', DispatchForm::HELPER, DispatchKinds::EVENT),
            // `broadcast($e)`
            self::helperFunction('broadcast', DispatchForm::HELPER, DispatchKinds::EVENT),
            // `broadcast_if($cond, $e)`: the event is argument 1
            self::helperFunction('broadcast_if', DispatchForm::HELPER, DispatchKinds::EVENT, argIndex: 1),
            // `broadcast_unless($cond, $e)`
            self::helperFunction('broadcast_unless', DispatchForm::HELPER, DispatchKinds::EVENT, argIndex: 1),
            // `dispatch($job)`, optionally followed by PendingDispatch modifiers
            self::helperFunction('dispatch', DispatchForm::JOB_HELPER, DispatchKinds::JOB),
            // `dispatch_sync($job)`
            self::helperFunction('dispatch_sync', DispatchForm::JOB_HELPER, DispatchKinds::JOB, DispatchMode::SYNC),

            // `Event::dispatch($e)`; the facade has no dispatchIf/dispatchUnless
            self::facade(Facades::EVENT, 'dispatch', DispatchForm::FACADE, DispatchKinds::EVENT),

            // `Bus::dispatch($job)`
            self::facade(Facades::BUS, 'dispatch', DispatchForm::JOB_HELPER, DispatchKinds::JOB),
            // `Bus::dispatchSync($job)`
            self::facade(Facades::BUS, 'dispatchSync', DispatchForm::JOB_HELPER, DispatchKinds::JOB, DispatchMode::SYNC),
            // `Bus::dispatchNow($job)`
            self::facade(Facades::BUS, 'dispatchNow', DispatchForm::JOB_HELPER, DispatchKinds::JOB, DispatchMode::SYNC),
            // `Bus::dispatchAfterResponse($job)`
            self::facade(Facades::BUS, 'dispatchAfterResponse', DispatchForm::JOB_HELPER, DispatchKinds::JOB, DispatchMode::AFTER_RESPONSE),
            // `Bus::chain([new A, new B])`: one site per array item
            self::facade(Facades::BUS, 'chain', DispatchForm::JOB_HELPER, DispatchKinds::JOB, target: DispatchTarget::LIST_ITEMS),
            // `Bus::batch([new A, new B])`
            self::facade(Facades::BUS, 'batch', DispatchForm::JOB_HELPER, DispatchKinds::JOB, target: DispatchTarget::LIST_ITEMS),

            // `Queue::push($job)`
            self::facade(Facades::QUEUE, 'push', DispatchForm::FACADE, DispatchKinds::JOB, DispatchMode::PUSH, DispatchTarget::ARGUMENT),
            // `Queue::pushOn($queue, $job)`
            self::facade(Facades::QUEUE, 'pushOn', DispatchForm::FACADE, DispatchKinds::JOB, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 1),
            // `Queue::later($delay, $job)`
            self::facade(Facades::QUEUE, 'later', DispatchForm::FACADE, DispatchKinds::JOB, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 1),
            // `Queue::laterOn($queue, $delay, $job)`
            self::facade(Facades::QUEUE, 'laterOn', DispatchForm::FACADE, DispatchKinds::JOB, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 2),
            // `Queue::bulk([new A, new B])`
            self::facade(Facades::QUEUE, 'bulk', DispatchForm::JOB_HELPER, DispatchKinds::JOB, DispatchMode::PUSH, DispatchTarget::LIST_ITEMS),

            // `Mail::send($mailable)`
            self::facade(Facades::MAIL, 'send', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, target: DispatchTarget::ARGUMENT),
            // `Mail::sendNow($mailable)`
            self::facade(Facades::MAIL, 'sendNow', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::SYNC, DispatchTarget::ARGUMENT),
            // `Mail::queue($mailable)`
            self::facade(Facades::MAIL, 'queue', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::PUSH, DispatchTarget::ARGUMENT),
            // `Mail::onQueue($queue, $mailable)`
            self::facade(Facades::MAIL, 'onQueue', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 1),
            // `Mail::queueOn($queue, $mailable)`
            self::facade(Facades::MAIL, 'queueOn', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 1),
            // `Mail::later($delay, $mailable)`
            self::facade(Facades::MAIL, 'later', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 1),
            // `Mail::laterOn($queue, $delay, $mailable)`
            self::facade(Facades::MAIL, 'laterOn', DispatchForm::MAIL_FACADE, DispatchKinds::MAILABLE, DispatchMode::PUSH, DispatchTarget::ARGUMENT, argIndex: 2),

            // `Notification::send($notifiables, $n, $channels)`; `send` is plain, ShouldQueue decides
            self::facade(Facades::NOTIFICATION, 'send', DispatchForm::NOTIFICATION_FACADE, DispatchKinds::NOTIFICATION, target: DispatchTarget::ARGUMENT, argIndex: 1, channelsAt: 2),
            // `Notification::sendNow($notifiables, $n, $channels)`
            self::facade(Facades::NOTIFICATION, 'sendNow', DispatchForm::NOTIFICATION_FACADE, DispatchKinds::NOTIFICATION, DispatchMode::SYNC, DispatchTarget::ARGUMENT, argIndex: 1, channelsAt: 2),

            // `Job::dispatch()`; event or job until the cross-link pass resolves it
            self::dispatchable('dispatch', DispatchKinds::AMBIGUOUS),
            // `Job::dispatchIf($cond, ...)`
            self::dispatchable('dispatchIf', DispatchKinds::AMBIGUOUS),
            // `Job::dispatchUnless($cond, ...)`
            self::dispatchable('dispatchUnless', DispatchKinds::AMBIGUOUS),
            // `Job::dispatchSync()`: exists only on the Bus Dispatchable trait, so it is a job
            self::dispatchable('dispatchSync', DispatchKinds::JOB, DispatchMode::SYNC),
            // `Job::dispatchAfterResponse()`
            self::dispatchable('dispatchAfterResponse', DispatchKinds::JOB, DispatchMode::AFTER_RESPONSE),

            // `Mail::to($u)->send($m)`, and the other terminals behind a Mail chain root
            self::mailTerminal('send'),
            // `Mail::to($u)->sendNow($m)`
            self::mailTerminal('sendNow', DispatchMode::SYNC),
            // `Mail::to($u)->queue($m)`
            self::mailTerminal('queue', DispatchMode::PUSH),
            // `Mail::to($u)->onQueue($queue, $m)`
            self::mailTerminal('onQueue', DispatchMode::PUSH, argIndex: 1),
            // `Mail::to($u)->queueOn($queue, $m)`
            self::mailTerminal('queueOn', DispatchMode::PUSH, argIndex: 1),
            // `Mail::to($u)->later($delay, $m)`
            self::mailTerminal('later', DispatchMode::PUSH, argIndex: 1),
            // `Mail::to($u)->laterOn($queue, $delay, $m)`
            self::mailTerminal('laterOn', DispatchMode::PUSH, argIndex: 2),

            // `Notification::route('mail', $a)->notify($n)`: rooted at the facade
            self::notify('notify', DispatchForm::NOTIFICATION_CHAIN, Facades::NOTIFICATION),
            // `Notification::route('mail', $a)->notifyNow($n)`
            self::notify('notifyNow', DispatchForm::NOTIFICATION_CHAIN, Facades::NOTIFICATION, DispatchMode::SYNC),
            // `$user->notify($n)`: any receiver, the Notifiable trait
            self::notify('notify', DispatchForm::NOTIFY_METHOD),
            // `$user->notifyNow($n)`
            self::notify('notifyNow', DispatchForm::NOTIFY_METHOD, mode: DispatchMode::SYNC),
        ];
    }

    /**
     * The table `EventDispatchSiteVisitor` evaluates: event-class discovery
     * reads only the event helpers, the Event facade and the Dispatchable
     * `dispatch*` statics (any class, other facades included).
     *
     * @return list<DispatchRule>
     */
    public static function eventDiscovery(): array
    {
        return array_values(collect(self::all())
            ->filter(fn (DispatchRule $rule): bool => match ($rule->shape) {
                // `event($e)` / `broadcast($e)`; the conditional and job helpers are not event discovery
                DispatchShape::GLOBAL_FUNCTION => $rule->kind === DispatchKinds::EVENT && $rule->argIndex === 0,
                // `Event::dispatch($e)`
                DispatchShape::FACADE_STATIC => $rule->facade === Facades::EVENT,
                // `X::dispatch()`, `X::dispatchIf()`, `X::dispatchUnless()`; sync/after-response are job-only
                DispatchShape::CLASS_STATIC => $rule->kind === DispatchKinds::AMBIGUOUS,
                DispatchShape::METHOD_CALL => false,
            })
            ->all());
    }

    /** `name(...)` with a PendingDispatch-aware first-class argument. */
    private static function helperFunction(string $name, DispatchForm $form, DispatchKinds $kind, ?DispatchMode $mode = null, int $argIndex = 0): DispatchRule
    {
        return new DispatchRule(DispatchShape::GLOBAL_FUNCTION, $name, $form, $kind, DispatchTarget::PENDING_ARGUMENT, argIndex: $argIndex, mode: $mode);
    }

    /** `Facade::name(...)`. */
    private static function facade(Facades $facade, string $name, DispatchForm $form, DispatchKinds $kind, ?DispatchMode $mode = null, DispatchTarget $target = DispatchTarget::PENDING_ARGUMENT, int $argIndex = 0, ?int $channelsAt = null): DispatchRule
    {
        return new DispatchRule(DispatchShape::FACADE_STATIC, $name, $form, $kind, $target, facade: $facade, argIndex: $argIndex, mode: $mode, channelsAt: $channelsAt);
    }

    /** `Class::name(...)`. */
    private static function dispatchable(string $name, DispatchKinds $kind, ?DispatchMode $mode = null): DispatchRule
    {
        return new DispatchRule(DispatchShape::CLASS_STATIC, $name, DispatchForm::DISPATCHABLE, $kind, DispatchTarget::STATIC_CLASS, mode: $mode);
    }

    /** `Mail::<root>()->...->name(...)`. */
    private static function mailTerminal(string $name, ?DispatchMode $mode = null, int $argIndex = 0): DispatchRule
    {
        return new DispatchRule(
            DispatchShape::METHOD_CALL,
            $name,
            DispatchForm::MAIL_CHAIN,
            DispatchKinds::MAILABLE,
            DispatchTarget::ARGUMENT,
            facade: Facades::MAIL,
            rootMethods: self::MAIL_ROOT_METHODS,
            argIndex: $argIndex,
            mode: $mode,
            labelPrefix: 'Mail::...->',
        );
    }

    /** `<receiver>->notify(...)`; rooted at `Notification::route()` when $root is given. */
    private static function notify(string $name, DispatchForm $form, ?Facades $root = null, ?DispatchMode $mode = null): DispatchRule
    {
        return new DispatchRule(
            DispatchShape::METHOD_CALL,
            $name,
            $form,
            DispatchKinds::NOTIFICATION,
            DispatchTarget::ARGUMENT,
            facade: $root,
            rootMethods: $root !== null ? ['route'] : [],
            mode: $mode,
            labelPrefix: '->',
        );
    }
}
