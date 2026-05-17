<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>}>
 */
function runSubscriberClassVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new SubscriberClassVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getClasses();
}

it('extracts handles from the return-array form of subscribe()', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class OrderEventSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => 'handleOrderPlaced',
                StockLow::class => 'handleStockLow',
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Listeners\\OrderEventSubscriber');
    expect($classes[0]['queued'])->toBeFalse();
    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handleOrderPlaced'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'handleStockLow'],
    ]);
});

it('extracts handles from [self::class, method] tuple values', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class TupleSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => [self::class, 'onPlaced'],
                StockLow::class => [static::class, 'onLow'],
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'onLow'],
    ]);
});

it('accepts the [Subscriber::class, method] tuple form using the subscriber FQCN', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class FqcnTupleSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => [FqcnTupleSubscriber::class, 'onPlaced'],
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('drops the handler pair when the tuple method is non-string but still emits the subscriber', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class DynamicMethodSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => [self::class, fn () => null],
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Listeners\\DynamicMethodSubscriber');
    expect($classes[0]['handles'])->toBe([]);
});

it('flags queued when the subscriber implements ShouldQueue', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use Illuminate\Contracts\Queue\ShouldQueue;

    class QueuedSubscriber implements ShouldQueue
    {
        public function subscribe($events): array
        {
            return [OrderPlaced::class => 'handle'];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queued'])->toBeTrue();
});

it('skips classes without a subscribe() method', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class NotASubscriber
    {
        public function handle(): void {}
    }
    PHP;

    expect(runSubscriberClassVisitor($source))->toBe([]);
});

it('returns empty handles when subscribe() does not return an array literal', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen('event', 'handler');
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([]);
});
