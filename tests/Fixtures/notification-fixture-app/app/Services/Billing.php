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

        /** @var \Illuminate\Notifications\Notification $dynamic */
        $dynamic = $this->resolveDynamic();
        $user->notify($dynamic);
    }

    private function resolveDynamic(): object
    {
        return new \stdClass();
    }
}
