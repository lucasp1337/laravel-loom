<?php

declare(strict_types=1);

namespace App\Services;

use App\Channels\SlackChannel;
use App\Notifications\ChannelOverrideAlert;
use App\Notifications\ChannelOverrideReminder;
use Illuminate\Support\Facades\Notification;

/**
 * Isolated fixture for issue #33 — the optional 3rd argument to
 * Notification::send/sendNow restricts dispatch to a literal channel set,
 * overriding the notification's via().
 *
 * Uses dedicated notification classes (ChannelOverride*) so the channel-filter
 * sites never perturb the method-equality and line-number invariants the
 * existing Billing/InvoicePaid assertions rely on.
 */
class ChannelFilterDispatcher
{
    /**
     * @param  array<int, object>  $users
     */
    public function dispatch(object $user, array $users): void
    {
        // Single string-literal channel filter.
        Notification::send($users, new ChannelOverrideAlert(), ['mail']);

        // Multi-channel filter, mixed case to lock lowercasing.
        Notification::sendNow($users, new ChannelOverrideReminder(), ['mail', 'DATABASE']);

        // Mixed string + custom-channel-class constant; the FQCN is surfaced.
        Notification::send($users, new ChannelOverrideAlert(), ['mail', SlackChannel::class]);

        // Empty-array filter collapses to no channels (treated as "no filter").
        Notification::send($users, new ChannelOverrideReminder(), []);

        // No-filter facade site: must carry NO channels key.
        Notification::sendNow($users, new ChannelOverrideAlert());

        // The notify-method form never captures channels even with via() overrides.
        $user->notify(new ChannelOverrideReminder());
    }
}
