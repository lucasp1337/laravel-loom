<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\IndirectlyQueuedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Isolated dispatch site for issue #32 override coverage. Kept separate from
 * Checkout so the existing OrderShipped/WelcomeEmail count and method
 * assertions in MailableScannerEndToEndTest stay untouched.
 */
class MailDispatcher
{
    public function dispatchWithOverrides(object $user): void
    {
        // (b) Mail facade-receiver chain (issue #32): locale/mailer modifiers
        // sit on the Mail::to(...) receiver chain ahead of the terminal send().
        // Expect overrides {locale, mailer} in sent_from[].
        Mail::to($user)->locale('fr')->mailer('ses')->send(new IndirectlyQueuedMail());

        // No-modifier control: a plain facade-receiver send must produce NO
        // overrides key (byte-identical to a pre-#32 entry).
        Mail::to($user)->send(new IndirectlyQueuedMail());
    }
}
