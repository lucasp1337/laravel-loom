<?php

declare(strict_types=1);

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Scanners\Dispatch\DispatchRule;
use Lucasp\Loom\Scanners\Dispatch\DispatchRuleMatcher;
use Lucasp\Loom\Scanners\Dispatch\DispatchRules;
use Lucasp\Loom\Scanners\Dispatch\DispatchShape;
use Lucasp\Loom\Scanners\Dispatch\DispatchTarget;
use Lucasp\Loom\Support\Ast\CallSite;

function matchDispatchRule(string $expr, ?DispatchRuleMatcher $matcher = null): ?DispatchRule
{
    $site = CallSite::of(parseExpr($expr));
    expect($site)->not->toBeNull();

    return ($matcher ?? DispatchRuleMatcher::forDispatchSites())->match($site);
}

/**
 * One entry per table row: the snippet, the row key it must resolve to, and the
 * form, kind, mode and argument index that row yields.
 *
 * @return array<string, array{0: string, 1: string, 2: DispatchForm, 3: DispatchKinds, 4: ?DispatchMode, 5: int}>
 */
function dispatchRuleCases(): array
{
    $h = DispatchForm::HELPER;
    $j = DispatchForm::JOB_HELPER;
    $f = DispatchForm::FACADE;
    $e = DispatchKinds::EVENT;
    $job = DispatchKinds::JOB;
    $mail = DispatchKinds::MAILABLE;
    $note = DispatchKinds::NOTIFICATION;
    $amb = DispatchKinds::AMBIGUOUS;

    return [
        'event()' => ['event($e)', 'global_function::event', $h, $e, null, 0],
        'broadcast()' => ['broadcast($e)', 'global_function::broadcast', $h, $e, null, 0],
        'broadcast_if()' => ['broadcast_if($c, $e)', 'global_function::broadcast_if', $h, $e, null, 1],
        'broadcast_unless()' => ['broadcast_unless($c, $e)', 'global_function::broadcast_unless', $h, $e, null, 1],
        'dispatch()' => ['dispatch($job)', 'global_function::dispatch', $j, $job, null, 0],
        'dispatch_sync()' => ['dispatch_sync($job)', 'global_function::dispatch_sync', $j, $job, DispatchMode::SYNC, 0],

        'Event::dispatch' => ['Event::dispatch($e)', 'facade_static:Event:dispatch', $f, $e, null, 0],

        'Bus::dispatch' => ['Bus::dispatch($job)', 'facade_static:Bus:dispatch', $j, $job, null, 0],
        'Bus::dispatchSync' => ['Bus::dispatchSync($job)', 'facade_static:Bus:dispatchSync', $j, $job, DispatchMode::SYNC, 0],
        'Bus::dispatchNow' => ['Bus::dispatchNow($job)', 'facade_static:Bus:dispatchNow', $j, $job, DispatchMode::SYNC, 0],
        'Bus::dispatchAfterResponse' => ['Bus::dispatchAfterResponse($job)', 'facade_static:Bus:dispatchAfterResponse', $j, $job, DispatchMode::AFTER_RESPONSE, 0],
        'Bus::chain' => ['Bus::chain([$a, $b])', 'facade_static:Bus:chain', $j, $job, null, 0],
        'Bus::batch' => ['Bus::batch([$a, $b])', 'facade_static:Bus:batch', $j, $job, null, 0],

        'Queue::push' => ['Queue::push($job)', 'facade_static:Queue:push', $f, $job, DispatchMode::PUSH, 0],
        'Queue::pushOn' => ['Queue::pushOn($q, $job)', 'facade_static:Queue:pushOn', $f, $job, DispatchMode::PUSH, 1],
        'Queue::later' => ['Queue::later($d, $job)', 'facade_static:Queue:later', $f, $job, DispatchMode::PUSH, 1],
        'Queue::laterOn' => ['Queue::laterOn($q, $d, $job)', 'facade_static:Queue:laterOn', $f, $job, DispatchMode::PUSH, 2],
        'Queue::bulk' => ['Queue::bulk([$a, $b])', 'facade_static:Queue:bulk', $j, $job, DispatchMode::PUSH, 0],

        'Mail::send' => ['Mail::send($m)', 'facade_static:Mail:send', DispatchForm::MAIL_FACADE, $mail, null, 0],
        'Mail::sendNow' => ['Mail::sendNow($m)', 'facade_static:Mail:sendNow', DispatchForm::MAIL_FACADE, $mail, DispatchMode::SYNC, 0],
        'Mail::queue' => ['Mail::queue($m)', 'facade_static:Mail:queue', DispatchForm::MAIL_FACADE, $mail, DispatchMode::PUSH, 0],
        'Mail::onQueue' => ['Mail::onQueue($q, $m)', 'facade_static:Mail:onQueue', DispatchForm::MAIL_FACADE, $mail, DispatchMode::PUSH, 1],
        'Mail::queueOn' => ['Mail::queueOn($q, $m)', 'facade_static:Mail:queueOn', DispatchForm::MAIL_FACADE, $mail, DispatchMode::PUSH, 1],
        'Mail::later' => ['Mail::later($d, $m)', 'facade_static:Mail:later', DispatchForm::MAIL_FACADE, $mail, DispatchMode::PUSH, 1],
        'Mail::laterOn' => ['Mail::laterOn($q, $d, $m)', 'facade_static:Mail:laterOn', DispatchForm::MAIL_FACADE, $mail, DispatchMode::PUSH, 2],

        'Notification::send' => ['Notification::send($u, $n)', 'facade_static:Notification:send', DispatchForm::NOTIFICATION_FACADE, $note, null, 1],
        'Notification::sendNow' => ['Notification::sendNow($u, $n)', 'facade_static:Notification:sendNow', DispatchForm::NOTIFICATION_FACADE, $note, DispatchMode::SYNC, 1],

        'X::dispatch' => ['Job::dispatch()', 'class_static::dispatch', DispatchForm::DISPATCHABLE, $amb, null, 0],
        'X::dispatchIf' => ['Job::dispatchIf($c)', 'class_static::dispatchIf', DispatchForm::DISPATCHABLE, $amb, null, 0],
        'X::dispatchUnless' => ['Job::dispatchUnless($c)', 'class_static::dispatchUnless', DispatchForm::DISPATCHABLE, $amb, null, 0],
        'X::dispatchSync' => ['Job::dispatchSync()', 'class_static::dispatchSync', DispatchForm::DISPATCHABLE, $job, DispatchMode::SYNC, 0],
        'X::dispatchAfterResponse' => ['Job::dispatchAfterResponse()', 'class_static::dispatchAfterResponse', DispatchForm::DISPATCHABLE, $job, DispatchMode::AFTER_RESPONSE, 0],

        'Mail::to()->send' => ['Mail::to($u)->send($m)', 'method_call:Mail:send', DispatchForm::MAIL_CHAIN, $mail, null, 0],
        'Mail::to()->sendNow' => ['Mail::to($u)->sendNow($m)', 'method_call:Mail:sendNow', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::SYNC, 0],
        'Mail::to()->queue' => ['Mail::to($u)->queue($m)', 'method_call:Mail:queue', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::PUSH, 0],
        'Mail::to()->onQueue' => ['Mail::to($u)->onQueue($q, $m)', 'method_call:Mail:onQueue', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::PUSH, 1],
        'Mail::to()->queueOn' => ['Mail::to($u)->queueOn($q, $m)', 'method_call:Mail:queueOn', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::PUSH, 1],
        'Mail::to()->later' => ['Mail::to($u)->later($d, $m)', 'method_call:Mail:later', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::PUSH, 1],
        'Mail::to()->laterOn' => ['Mail::to($u)->laterOn($q, $d, $m)', 'method_call:Mail:laterOn', DispatchForm::MAIL_CHAIN, $mail, DispatchMode::PUSH, 2],

        'Notification::route()->notify' => ["Notification::route('mail', \$a)->notify(\$n)", 'method_call:Notification:notify', DispatchForm::NOTIFICATION_CHAIN, $note, null, 0],
        'Notification::route()->notifyNow' => ["Notification::route('mail', \$a)->notifyNow(\$n)", 'method_call:Notification:notifyNow', DispatchForm::NOTIFICATION_CHAIN, $note, DispatchMode::SYNC, 0],
        '$x->notify' => ['$user->notify($n)', 'method_call::notify', DispatchForm::NOTIFY_METHOD, $note, null, 0],
        '$x->notifyNow' => ['$user->notifyNow($n)', 'method_call::notifyNow', DispatchForm::NOTIFY_METHOD, $note, DispatchMode::SYNC, 0],
    ];
}

it('resolves every rule row', function (string $expr, string $key, DispatchForm $form, DispatchKinds $kind, ?DispatchMode $mode, int $argIndex): void {
    $rule = matchDispatchRule($expr);

    expect($rule)->not->toBeNull()
        ->and($rule->key())->toBe($key)
        ->and($rule->form)->toBe($form)
        ->and($rule->kind)->toBe($kind)
        ->and($rule->mode)->toBe($mode)
        ->and($rule->argIndex)->toBe($argIndex);
})->with(dispatchRuleCases());

it('covers every row of the table exactly once', function (): void {
    $keys = collect(DispatchRules::all())->map(fn (DispatchRule $r): string => $r->key());
    $covered = collect(dispatchRuleCases())->map(fn (array $c): string => $c[1]);

    expect($keys->duplicates()->all())->toBe([])
        ->and($keys->sort()->values()->all())->toBe($covered->sort()->values()->all());
});

it('derives each row label from its shape', function (string $expr, string $label): void {
    expect(matchDispatchRule($expr)?->label())->toBe($label);
})->with([
    'function' => ['broadcast_if($c, $e)', 'broadcast_if'],
    'facade' => ['Queue::laterOn($q, $d, $j)', 'Queue::laterOn'],
    'mail chain' => ['Mail::to($u)->send($m)', 'Mail::...->send'],
    'notify' => ['$u->notify($n)', '->notify'],
]);

it('reads the argument and chain shape of a row from its target', function (string $expr, DispatchTarget $target, ?int $channelsAt): void {
    $rule = matchDispatchRule($expr);

    expect($rule?->target)->toBe($target)
        ->and($rule?->channelsAt)->toBe($channelsAt);
})->with([
    'helper is ternary aware' => ['event($e)', DispatchTarget::PENDING_ARGUMENT, null],
    'queue push is plain argument' => ['Queue::push($j)', DispatchTarget::ARGUMENT, null],
    'bus chain is a list' => ['Bus::chain([])', DispatchTarget::LIST_ITEMS, null],
    'dispatchable is the class' => ['Job::dispatch()', DispatchTarget::STATIC_CLASS, null],
    'notification facade takes channels' => ['Notification::send($u, $n)', DispatchTarget::ARGUMENT, 2],
]);

it('matches nothing for shapes outside the table', function (string $expr): void {
    expect(matchDispatchRule($expr))->toBeNull();
})->with([
    'unknown function' => ['listen($e)'],
    'dynamic function' => ['$f($e)'],
    'dynamic static class' => ['$class::dispatch()'],
    'dynamic static method' => ['Job::$m()'],
    'dynamic method' => ['$x->$m($n)'],
    'new expression' => ['new Foo($e)'],
    'unknown method' => ['$x->handle($e)'],
    'unlisted Mail static' => ['Mail::raw($t, $cb)'],
    'unlisted Bus static' => ['Bus::dispatchIf($c, $j)'],
    'unlisted Queue static' => ['Queue::dispatch($j)'],
    'unlisted Event static' => ['Event::dispatchIf($c, $e)'],
    'Event::listen' => ['Event::listen($e, $l)'],
    'mail terminal on a plain receiver' => ['$mailer->send($m)'],
    'mail terminal behind a non-root call' => ['Mail::pretend()->send($m)'],
    'mail terminal behind an unrelated facade' => ['Cache::to($u)->send($m)'],
    'notification route misuse' => ['Notification::locale($l)->send($n)'],
]);

it('is case-insensitive for function names and alias or FQCN for facades', function (string $expr, string $key): void {
    expect(matchDispatchRule($expr)?->key())->toBe($key);
})->with([
    'uppercase function' => ['EVENT($e)', 'global_function::event'],
    'leading backslash function' => ['\event($e)', 'global_function::event'],
    'facade FQCN' => ['\Illuminate\Support\Facades\Bus::dispatch($j)', 'facade_static:Bus:dispatch'],
    'rooted via FQCN' => ['\Illuminate\Support\Facades\Mail::to($u)->send($m)', 'method_call:Mail:send'],
]);

it('lets an owned facade claim its static calls, so only other classes are Dispatchable', function (): void {
    // Route is not a rule-table facade, so Route::dispatch() is a Dispatchable form.
    expect(matchDispatchRule('Route::dispatch($j)')?->key())->toBe('class_static::dispatch')
        ->and(matchDispatchRule('static::dispatch()')?->key())->toBe('class_static::dispatch');
});

it('walks a chain through dynamic and unrelated links to its facade root', function (): void {
    // The receiver walk only follows `->var`; link names in the middle do not matter.
    expect(matchDispatchRule('Mail::to($u)->foo()->send($m)')?->key())->toBe('method_call:Mail:send')
        ->and(matchDispatchRule('Mail::to($u)->{$x}()->send($m)')?->key())->toBe('method_call:Mail:send');
});

it('prefers the facade-rooted notify row over the any-receiver row', function (): void {
    expect(matchDispatchRule("Notification::route('mail', \$a)->route('sms', \$b)->notify(\$n)")?->form)->toBe(DispatchForm::NOTIFICATION_CHAIN)
        ->and(matchDispatchRule('$user->route()->notify($n)')?->form)->toBe(DispatchForm::NOTIFY_METHOD);
});

it('lets a custom table add a dispatch form with one row', function (): void {
    $matcher = new DispatchRuleMatcher([
        new DispatchRule(DispatchShape::GLOBAL_FUNCTION, 'fire', DispatchForm::HELPER, DispatchKinds::EVENT, DispatchTarget::PENDING_ARGUMENT),
    ]);

    expect(matchDispatchRule('fire($e)', $matcher)?->key())->toBe('global_function::fire')
        ->and(matchDispatchRule('event($e)', $matcher))->toBeNull();
});
