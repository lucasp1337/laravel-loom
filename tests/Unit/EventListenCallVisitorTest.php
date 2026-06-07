<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run EventListenCallVisitor (after NameResolver) over it.
 *
 * @return array<int, array{event: string, listener: string, method: string}>
 */
function runEventListenCallVisitor(string $source): array
{
    return runEventListenCallVisitorFull($source)['pairs'];
}

/**
 * @return array{pairs: array<int, array{event: string, listener: string, method: string}>, closurePairs: array<int, array{event: string, line: int, registration: ListenerRegistration}>}
 */
function runEventListenCallVisitorFull(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EventListenCallVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return [
        'pairs' => $visitor->getPairs(),
        'closurePairs' => $visitor->getClosurePairs(),
    ];
}

it('extracts a pair from Event::listen with the facade imported via use, defaulting method to handle', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('extracts the explicit method from a [Listener::class, method] tuple', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, [SendOrderConfirmation::class, 'onPlaced']);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'onPlaced'));
});

it('emits one pair per event for an array-of-events first arg with a class-string listener', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\Foo;
    use App\Events\Bar;
    use App\Listeners\SomeListener;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen([Foo::class, Bar::class], SomeListener::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(2);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\Foo', listener: 'App\\Listeners\\SomeListener', method: 'handle'));
    expect($pairs[1])->toEqual(new ListenerPair(event: 'App\\Events\\Bar', listener: 'App\\Listeners\\SomeListener', method: 'handle'));
});

it('emits one pair per event for an array-of-events first arg with a tuple listener', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\Foo;
    use App\Events\Bar;
    use App\Listeners\SomeListener;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen([Foo::class, Bar::class], [SomeListener::class, 'onEvent']);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(2);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\Foo', listener: 'App\\Listeners\\SomeListener', method: 'onEvent'));
    expect($pairs[1])->toEqual(new ListenerPair(event: 'App\\Events\\Bar', listener: 'App\\Listeners\\SomeListener', method: 'onEvent'));
});

it('emits one closure pair per event for an array-of-events first arg with a closure listener', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\Foo;
    use App\Events\Bar;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen([Foo::class, Bar::class], fn ($e) => null);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(2);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\Foo');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
    expect($result['closurePairs'][1]->event)->toBe('App\\Events\\Bar');
    expect($result['closurePairs'][1]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
});

it('still emits exactly one pair for a single class-string event (regression for the array refactor)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\Foo;
    use App\Listeners\SomeListener;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(Foo::class, SomeListener::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\Foo', listener: 'App\\Listeners\\SomeListener', method: 'handle'));
});

it('skips Event::listen calls whose event argument is a variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen($dynamicEvent, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('skips Event::listen calls whose listener argument is a closure', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, fn ($e) => null);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('extracts a pair when the Event facade is referenced via its FQCN with no use statement', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class AppServiceProvider
    {
        public function boot(): void
        {
            \Illuminate\Support\Facades\Event::listen(
                \App\Events\OrderPlaced::class,
                \App\Listeners\SendOrderConfirmation::class
            );
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('emits a closure pair from Event::listen(Foo::class, fn ($e) => …)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, fn ($e) => null);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
    expect($result['closurePairs'][0]->line)->toBeInt()->toBeGreaterThan(0);
});

it('emits a closure pair with a string event from Event::listen(\'string.event\', fn () => …)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('user.created', fn ($e) => null);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('user.created');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
});

it('drops Event::listen($var, fn () => …) entirely', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen($dynamic, fn ($e) => null);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toBe([]);
});

it('emits a closure pair from Event::listen(Foo::class, function () { … }) long-form', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, function ($e) {
                return null;
            });
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
});

// -----------------------------------------------------------------------------
// Callable-shaped regular listeners
// -----------------------------------------------------------------------------

it('extracts a pair from Event::listen(Foo::class, Closure::fromCallable([Listener::class, method]))', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\OrderEventsHandler;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, \Closure::fromCallable([OrderEventsHandler::class, 'handlePlaced']));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\OrderEventsHandler', method: 'handlePlaced'));
});

it('extracts a pair from a Closure::fromCallable imported via use (no leading backslash)', function () {
    // With `use Closure;` the NameResolver resolves the bare `Closure` to the
    // global \Closure, exercising the same detection path as the FQCN form.
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\OrderEventsHandler;
    use Closure;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, Closure::fromCallable([OrderEventsHandler::class, 'handlePlaced']));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\OrderEventsHandler', method: 'handlePlaced'));
});

it('defaults the method to handle for single-element Closure::fromCallable([Listener::class])', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, \Closure::fromCallable([SendOrderConfirmation::class]));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('extracts a pair from Event::listen(Foo::class, Listener::method(...))', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\OrderEventsHandler;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, OrderEventsHandler::handlePlaced(...));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\OrderEventsHandler', method: 'handlePlaced'));
});

it('drops Event::listen(Foo::class, Closure::fromCallable($var))', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, \Closure::fromCallable($var));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('drops Event::listen(Foo::class, \'Listener::method\') string callable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, 'App\\Listeners\\OrderEventsHandler::handlePlaced');
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('drops Event::listen(Foo::class, $obj->method(...)) instance first-class callable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen(OrderPlaced::class, $handler->handlePlaced(...));
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('ignores static listen calls on classes other than the Event facade', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Support\Facades\Cache;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Cache::listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

// -----------------------------------------------------------------------------
// Container-form receivers ($this->app['events'], app(Dispatcher::class), …)
// -----------------------------------------------------------------------------

it('extracts a pair from Shape A: $this->app[\'events\']->listen(...)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $this->app['events']->listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('extracts a pair from Shape B: app(Dispatcher::class)->listen(...)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            app(Dispatcher::class)->listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('extracts a pair from Shape C: a variable assigned from a Dispatcher resolution earlier', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $dispatcher = app(Dispatcher::class);
            $dispatcher->listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('drops a Shape C variable listen after it is reassigned to a non-Dispatcher value', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $dispatcher = app(Dispatcher::class);
            $dispatcher = new \stdClass();
            $dispatcher->listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('resolves a tuple listener via the container path', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            app(Dispatcher::class)->listen(OrderPlaced::class, [SendOrderConfirmation::class, 'onPlaced']);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'onPlaced'));
});

it('lands an inline closure registered via the container path in closure pairs', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            app(Dispatcher::class)->listen(OrderPlaced::class, fn ($e) => null);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
});

it('does not emit a pair for ->listen() on an untracked variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $logger->listen(OrderPlaced::class, SendOrderConfirmation::class);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('does not emit a wildcard string event registered via the container path', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\SendOrderConfirmation;
    use Illuminate\Contracts\Events\Dispatcher;

    class AppServiceProvider
    {
        public function boot(): void
        {
            app(Dispatcher::class)->listen('eloquent.*', SendOrderConfirmation::class);
        }
    }
    PHP;

    $result = runEventListenCallVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toBe([]);
});
