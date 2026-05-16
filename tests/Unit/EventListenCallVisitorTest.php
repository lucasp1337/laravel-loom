<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\EventListenCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run EventListenCallVisitor (after NameResolver) over it.
 *
 * @return array<int, array{event: string, listener: string}>
 */
function runEventListenCallVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EventListenCallVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getPairs();
}

it('extracts a pair from Event::listen with the facade imported via use', function () {
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
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\SendOrderConfirmation']);
});

it('extracts a pair from Event::listen with a [Listener::class, method] tuple, discarding the method', function () {
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
            Event::listen(OrderPlaced::class, [SendOrderConfirmation::class, 'handle']);
        }
    }
    PHP;

    $pairs = runEventListenCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\SendOrderConfirmation']);
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
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\SendOrderConfirmation']);
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
