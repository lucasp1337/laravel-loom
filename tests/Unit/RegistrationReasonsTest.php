<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;

/** @return list<string> "expression|reason" per unresolved entry */
function registrationReasons(object $visitor): array
{
    return collect($visitor->getUnresolved())->map(fn ($r) => $r->expression.'|'.$r->reason)->all();
}

it('splits a $listen key from its handler', function () {
    $visitor = runVisitor(new ListenArrayVisitor, <<<'PHP'
    <?php
    namespace App\Providers;
    class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
    {
        protected $listen = [
            $name => [\App\Listeners\A::class],
            \App\Events\Placed::class => [$listener],
        ];
    }
    PHP);

    expect(registrationReasons($visitor))->toBe(['$name|unreadable_event_name', '$listener|unreadable_listener']);
});

it('reports a $subscribe entry that is not a class as unreadable_subscriber', function () {
    $visitor = runVisitor(new SubscribeArrayVisitor, <<<'PHP'
    <?php
    namespace App\Providers;
    class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
    {
        protected $subscribe = [$subscriber];
    }
    PHP);

    expect(registrationReasons($visitor))->toBe(['$subscriber|unreadable_subscriber']);
});

it('reports a subscriber return-array key it cannot read as unreadable_event_name', function () {
    $visitor = runVisitor(new SubscriberClassVisitor, <<<'PHP'
    <?php
    namespace App\Listeners;
    class OrderSubscriber
    {
        public function subscribe($events): array
        {
            return [$name => 'onPlaced'];
        }
    }
    PHP);

    expect(registrationReasons($visitor))->toBe(['$name|unreadable_event_name']);
});
