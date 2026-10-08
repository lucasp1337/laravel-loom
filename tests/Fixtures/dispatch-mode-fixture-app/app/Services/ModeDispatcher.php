<?php

namespace App\Services;

use App\Jobs\Deferred;
use App\Jobs\Inline;
use App\Jobs\NotAfterResponse;
use App\Jobs\Plain;
use App\Jobs\Pushed;
use App\Mail\Digest;
use App\Mail\Receipt;
use App\Notifications\Alert;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

class ModeDispatcher
{
    public function plain(): void
    {
        Plain::dispatch();
        dispatch(new Plain);
        Bus::dispatch(new Plain);
    }

    public function syncForms(): void
    {
        Inline::dispatchSync();
        dispatch_sync(new Inline);
        Bus::dispatchSync(new Inline);
        Bus::dispatchNow(new Inline);
    }

    public function afterResponseForms(): void
    {
        Deferred::dispatchAfterResponse();
        Bus::dispatchAfterResponse(new Deferred);
        Deferred::dispatch()->afterResponse();
        dispatch(new Deferred)->afterResponse(true);
        Deferred::dispatch()->onQueue('low')->afterResponse();
    }

    public function afterResponseDisabled(): void
    {
        NotAfterResponse::dispatch()->afterResponse(false);
    }

    public function queueFacade(): void
    {
        Queue::push(new Pushed);
        Queue::pushOn('high', new Pushed);
        Queue::later(60, new Pushed);
        Queue::laterOn('low', 60, new Pushed);
        Queue::bulk([new Pushed, new Plain]);
        Queue::pushRaw('{"job":"x"}');
    }

    public function dynamic(string $class): void
    {
        $job = new $class;
        dispatch_sync($job);
        Queue::push($job);
    }

    public function mail(): void
    {
        Mail::to('a@example.com')->send(new Receipt);
        Mail::to('a@example.com')->sendNow(new Receipt);
        Mail::to('a@example.com')->queue(new Digest);
        Mail::to('a@example.com')->later(60, new Digest);
        Mail::send(new Receipt);
        Mail::sendNow(new Receipt);
        Mail::queue(new Digest);
        Mail::later(60, new Digest);
        Mail::onQueue('mail', new Digest);
        Mail::laterOn('mail', 60, new Digest);
    }

    public function notifications(object $user): void
    {
        $user->notify(new Alert);
        $user->notifyNow(new Alert);
        Notification::send($user, new Alert);
        Notification::sendNow($user, new Alert, ['mail']);
    }
}
