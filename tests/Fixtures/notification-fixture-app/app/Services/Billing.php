<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Accounts\Notifications\InvitedNotification;
use App\Notifications\InvoicePaid;
use App\Notifications\PasswordReset;
use Illuminate\Support\Facades\Notification;

class Billing
{
    /**
     * @param  array<int, object>  $users
     */
    public function chargeAndNotify(object $user, array $users): void
    {
        $user->notify(new InvoicePaid());

        $user->notifyNow(new PasswordReset());

        Notification::send($users, new InvoicePaid());

        Notification::sendNow($users, new PasswordReset());

        Notification::route('mail', 'foo@example.com')->notify(new InvitedNotification());

        // Multi-route chain: arbitrary depth of ->route(...) links between the
        // facade and the terminating ->notify(). The chain walker must follow
        // every MethodCall->var link down to the Notification::route static
        // call to recognise this shape.
        Notification::route('mail', 'foo@example.com')
            ->route('vonage', '+15555555555')
            ->notify(new InvitedNotification());

        // Class-constant target form. Locks parity with the new Y() form
        // across both the Notifiable-trait instance method and the
        // Notification facade.
        $user->notify(InvoicePaid::class);

        Notification::send($users, PasswordReset::class);

        /** @var \Illuminate\Notifications\Notification $dynamic */
        $dynamic = $this->resolveDynamic();
        $user->notify($dynamic);

        // Chain-wrapped dispatch targets (issue #31). The leading fluent
        // MethodCall chain must be unwrapped so the FQCN resolves to a real
        // notification and lands in notified_from[], NOT unresolved_dispatches[].
        $user->notify((new InvoicePaid())->locale('es'));

        Notification::send($users, (new InvoicePaid())->onQueue('emails'));
    }

    /**
     * @param  array<int, object>  $users
     */
    public function notifyWithOverrides(object $user, array $users): void
    {
        // (a) inner argument-instance chain with multiple modifiers (issue #32).
        // Expect overrides {connection, queue} in notified_from[].
        $user->notify((new PasswordReset())->onConnection('redis')->onQueue('high'));

        // No-modifier control: a plain notify must produce NO overrides key.
        Notification::send($users, new PasswordReset());
    }

    private function resolveDynamic(): object
    {
        return new \stdClass();
    }
}
