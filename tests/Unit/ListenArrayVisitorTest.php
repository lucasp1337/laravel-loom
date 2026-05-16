<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\ListenArrayVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run ListenArrayVisitor (after NameResolver) over it.
 *
 * @return array<int, array{event: string, listener: string}>
 */
function runListenArrayVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ListenArrayVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getPairs();
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
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\SendOrderConfirmation']);
    expect($pairs[1])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\UpdateInventory']);
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
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\SendOrderConfirmation']);
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

it('extracts the listener FQCN from a tuple value and discards the method', function () {
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
    expect($pairs[0])->toBe(['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Listeners\\PsrOnly']);
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
