<?php

declare(strict_types=1);

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
 * @return array{pairs: array<int, array{event: string, listener: string, method: string}>, closurePairs: array<int, array{event: string, line: int, registration: string}>}
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
    expect($pairs[0])->toBe([
        'event' => 'App\\Events\\OrderPlaced',
        'listener' => 'App\\Listeners\\SendOrderConfirmation',
        'method' => 'handle',
    ]);
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
    expect($pairs[0])->toBe([
        'event' => 'App\\Events\\OrderPlaced',
        'listener' => 'App\\Listeners\\SendOrderConfirmation',
        'method' => 'onPlaced',
    ]);
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
    expect($pairs[0])->toBe([
        'event' => 'App\\Events\\OrderPlaced',
        'listener' => 'App\\Listeners\\SendOrderConfirmation',
        'method' => 'handle',
    ]);
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
    expect($result['closurePairs'][0]['event'])->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]['registration'])->toBe('event_listen_call');
    expect($result['closurePairs'][0]['line'])->toBeInt()->toBeGreaterThan(0);
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
    expect($result['closurePairs'][0]['event'])->toBe('user.created');
    expect($result['closurePairs'][0]['registration'])->toBe('event_listen_call');
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
    expect($result['closurePairs'][0]['event'])->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]['registration'])->toBe('event_listen_call');
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
