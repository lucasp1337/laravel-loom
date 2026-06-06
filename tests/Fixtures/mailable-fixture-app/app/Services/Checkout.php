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

        // Class-constant target form: Mail::send(X::class). Locks parity
        // with the new X() form — DispatchSiteVisitor::resolveStaticClass
        // accepts both shapes.
        Mail::send(OrderShipped::class);

        // Chain-wrapped dispatch target: the mailable argument is
        // itself a fluent chain `(new OrderShipped())->locale('fr')`. The
        // leading MethodCall chain must be unwrapped so this lands in
        // sent_from[], NOT unresolved_dispatches[].
        Mail::to($user)->send((new OrderShipped())->locale('fr'));

        /** @var \Illuminate\Mail\Mailable $dynamicMailable */
        $dynamicMailable = $this->resolveDynamic();
        Mail::send($dynamicMailable);
    }

    private function resolveDynamic(): object
    {
        return new \stdClass();
    }
}
