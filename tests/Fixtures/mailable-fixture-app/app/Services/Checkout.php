<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Billing\Mail\InvoiceMailable;
use App\Mail\OrderShipped;
use App\Mail\WelcomeEmail;
use Illuminate\Support\Facades\Mail;

class Checkout
{
    public function finalize(int $orderId, object $user, object $manager, object $audit): void
    {
        Mail::send(new OrderShipped());

        Mail::queue(new WelcomeEmail());

        Mail::later(now()->addMinutes(10), new OrderShipped());

        Mail::to($user)->send(new OrderShipped());

        Mail::to($user)->cc($manager)->bcc($audit)->send(new OrderShipped());

        Mail::to($user)->locale('fr')->send(new WelcomeEmail());

        Mail::send(new InvoiceMailable());

        /** @var \Illuminate\Mail\Mailable $dynamicMailable */
        $dynamicMailable = $this->resolveDynamic();
        Mail::send($dynamicMailable);
    }

    private function resolveDynamic(): object
    {
        return new \stdClass();
    }
}
