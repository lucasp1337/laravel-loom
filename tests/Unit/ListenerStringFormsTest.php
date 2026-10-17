<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\ModelHookCallVisitor;

it('folds Listener::class.\'@method\' and Fqcn@method in $listen and Event::listen', function () {
    $listen = runVisitor(new ListenArrayVisitor, <<<'PHP'
    <?php
    namespace App\Providers;
    use App\Events\OrderShipped;
    use App\Listeners\ShipListener;
    class EventServiceProvider
    {
        protected $listen = [
            'App\Events\OrderShipped' => [ShipListener::class.'@handle', 'App\Listeners\Other@run'],
            OrderShipped::class => ['App\Listeners\Plain'],
        ];
    }
    PHP);

    expect(collect($listen->getPairs())->map(fn ($p) => "{$p->event}|{$p->listener}|{$p->method}")->all())->toBe([
        'App\Events\OrderShipped|App\Listeners\ShipListener|handle',
        'App\Events\OrderShipped|App\Listeners\Other|run',
        'App\Events\OrderShipped|App\Listeners\Plain|handle',
    ]);
    expect($listen->getUnresolved())->toBe([]);

    $calls = runVisitor(new EventListenCallVisitor, <<<'PHP'
    <?php
    namespace App\Providers;
    use App\Events\OrderShipped;
    use App\Listeners\ShipListener;
    use Illuminate\Support\Facades\Event;
    class P
    {
        public function boot(): void
        {
            Event::listen(OrderShipped::class, ShipListener::class.'@handle');
            Event::listen(OrderShipped::class, 'App\Listeners\ShipListener@notify');
            Event::listen('orders.shipped', [ShipListener::class, 'handle']);
        }
    }
    PHP);

    expect(collect($calls->getPairs())->map(fn ($p) => "{$p->event}|{$p->listener}|{$p->method}")->all())->toBe([
        'App\Events\OrderShipped|App\Listeners\ShipListener|handle',
        'App\Events\OrderShipped|App\Listeners\ShipListener|notify',
        'orders.shipped|App\Listeners\ShipListener|handle',
    ]);
});

it('reports listener and event shapes it cannot fold as unresolved', function () {
    $visitor = runVisitor(new EventListenCallVisitor, <<<'PHP'
    <?php
    namespace App\Providers;
    use Illuminate\Support\Facades\Event;
    class P
    {
        public function boot(string $listener, string $event): void
        {
            Event::listen(Foo::class, $listener);
            Event::listen($event, Foo::class);
            Event::listen(Foo::class, 'App\Listeners\X::handle');
        }
    }
    PHP);

    expect($visitor->getPairs())->toBe([]);
    expect(collect($visitor->getUnresolved())->map(fn ($r) => $r->expression.'|'.$r->reason)->all())->toBe([
        '$listener|unreadable_listener',
        '$event|unreadable_event_name',
        "'App\\Listeners\\X::handle'|unreadable_listener",
    ]);
});

it('reads closure, tuple and class model hooks and reports unreadable ones', function () {
    $visitor = runVisitor(new ModelHookCallVisitor, <<<'PHP'
    <?php
    namespace App\Models;
    use App\Hooks\AuditHook;
    class Order
    {
        protected static function booted(): void
        {
            static::creating(function (Order $order) { $order->uuid = 1; });
            static::deleted(fn (Order $order) => 1);
            static::saved([AuditHook::class, 'saved']);
            static::updated(AuditHook::class);
            static::softDeleted(fn () => 1);
            static::saving($callback);
            parent::creating(fn () => 1);
        }
    }
    PHP);

    expect(collect($visitor->getEntries())->map(fn ($e) => "{$e->hook}|{$e->handler}|{$e->method}")->all())->toBe([
        'creating|App\Models\Order|{closure}',
        'deleted|App\Models\Order|{closure}',
        'saved|App\Hooks\AuditHook|saved',
        'updated|App\Hooks\AuditHook|handle',
        'trashed|App\Models\Order|{closure}',
    ]);
    expect(collect($visitor->getUnresolved())->map(fn ($r) => $r->expression.'|'.$r->reason)->all())->toBe([
        '$callback|unreadable_model_hook',
        'fn () => ...|relative_class_name',
    ]);
});

it('marks hooks declared in a trait', function () {
    $visitor = runVisitor(new ModelHookCallVisitor, <<<'PHP'
    <?php
    namespace App\Concerns;
    trait HasUuid
    {
        public static function bootHasUuid(): void
        {
            static::creating(fn ($model) => 1);
        }
    }
    PHP);

    $entry = $visitor->getEntries()[0];
    expect($entry->viaTrait)->toBeTrue();
    expect($entry->model)->toBe('App\Concerns\HasUuid');
});
