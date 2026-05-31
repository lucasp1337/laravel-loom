<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run ListenArrayVisitor (after NameResolver) over it.
 *
 * @return array<int, array{event: string, listener: string, method: string}>
 */
function runListenArrayVisitor(string $source): array
{
    return runListenArrayVisitorFull($source)['pairs'];
}

/**
 * @return array{pairs: array<int, array{event: string, listener: string, method: string}>, closurePairs: array<int, array{event: string, line: int, registration: ListenerRegistration}>}
 */
function runListenArrayVisitorFull(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ListenArrayVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return [
        'pairs' => $visitor->getPairs(),
        'closurePairs' => $visitor->getClosurePairs(),
    ];
}

it('extracts pairs from a class literally named EventServiceProvider', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;
    use App\Listeners\UpdateInventory;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                SendOrderConfirmation::class,
                UpdateInventory::class,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toHaveCount(2);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
    expect($pairs[1])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\UpdateInventory', method: 'handle'));
});

it('extracts pairs from a class extending the EventServiceProvider base FQCN under a different short name', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class WiringProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                SendOrderConfirmation::class,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('ignores $listen on a class that is neither named nor extending EventServiceProvider', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class SomeOtherProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                SendOrderConfirmation::class,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toBe([]);
});

it('extracts the listener FQCN and method from a tuple value', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\PsrOnly;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                [PsrOnly::class, 'someMethod'],
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\PsrOnly', method: 'someMethod'));
});

it('defaults the method to "handle" for a bare Listener::class value', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                SendOrderConfirmation::class,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toEqual(new ListenerPair(event: 'App\\Events\\OrderPlaced', listener: 'App\\Listeners\\SendOrderConfirmation', method: 'handle'));
});

it('skips closure listener values', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                fn () => null,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toBe([]);
});

it('skips entries with string keys instead of ::class', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\SendOrderConfirmation;

    class EventServiceProvider
    {
        protected $listen = [
            'eloquent.*' => [
                SendOrderConfirmation::class,
            ],
        ];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toBe([]);
});

it('emits a closure pair when the $listen value is an arrow function', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                fn ($e) => null,
            ],
        ];
    }
    PHP;

    $result = runListenArrayVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::LISTEN_ARRAY);
    expect($result['closurePairs'][0]->line)->toBeInt()->toBeGreaterThan(0);
});

it('emits a closure pair when the $listen value is a long-form Closure', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                function ($e) { return null; },
            ],
        ];
    }
    PHP;

    $result = runListenArrayVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::LISTEN_ARRAY);
});

it('routes class listeners to getPairs() and closures to getClosurePairs() in a mixed array', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Events\OrderPlaced;
    use App\Listeners\SendOrderConfirmation;

    class EventServiceProvider
    {
        protected $listen = [
            OrderPlaced::class => [
                SendOrderConfirmation::class,
                fn ($e) => null,
            ],
        ];
    }
    PHP;

    $result = runListenArrayVisitorFull($source);

    expect($result['pairs'])->toHaveCount(1);
    expect($result['pairs'][0]->listener)->toBe('App\\Listeners\\SendOrderConfirmation');
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('App\\Events\\OrderPlaced');
});

it('emits a closure pair with a string event key', function () {
    // String event keys are legitimate for closures (Event::listen('user.created', fn (…)))
    // but the same string key under a non-closure value belongs to ObserverScanner.
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class EventServiceProvider
    {
        protected $listen = [
            'user.created' => [
                fn ($e) => null,
            ],
        ];
    }
    PHP;

    $result = runListenArrayVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toHaveCount(1);
    expect($result['closurePairs'][0]->event)->toBe('user.created');
    expect($result['closurePairs'][0]->registration)->toBe(ListenerRegistration::LISTEN_ARRAY);
});

it('drops both pair and closure-pair when the key is a variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class EventServiceProvider
    {
        protected $listen = [
            $event => [
                fn ($e) => null,
            ],
        ];
    }
    PHP;

    $result = runListenArrayVisitorFull($source);

    expect($result['pairs'])->toBe([]);
    expect($result['closurePairs'])->toBe([]);
});

it('returns no pairs for an empty $listen array', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class EventServiceProvider
    {
        protected $listen = [];
    }
    PHP;

    $pairs = runListenArrayVisitor($source);

    expect($pairs)->toBe([]);
});
