<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>, closureHandles: array<int, array{event: string, line: int}>, foreignPairs: array<int, array{event: string, listener: string, method: string}>}>
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

it('routes closure values in subscribe() return array to closureHandles', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class MixedSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => 'handleOrderPlaced',
                StockLow::class => fn ($e) => null,
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handleOrderPlaced'],
    ]);
    expect($classes[0]['closureHandles'])->toHaveCount(1);
    expect($classes[0]['closureHandles'][0]['event'])->toBe('App\\Events\\StockLow');
    expect($classes[0]['closureHandles'][0]['line'])->toBeInt()->toBeGreaterThan(0);
});

it('routes long-form closure values in subscribe() return array to closureHandles', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class LongFormSubscriber
    {
        public function subscribe($events): array
        {
            return [
                OrderPlaced::class => function ($e) {
                    return null;
                },
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toHaveCount(1);
    expect($classes[0]['closureHandles'][0]['event'])->toBe('App\\Events\\OrderPlaced');
});

it('drops non-closure handlers under string event keys and keeps closure handlers under string keys', function () {
    // String event keys for non-closure handlers belong to ObserverScanner;
    // string keys for closures are still legal (subscriber can listen to 'user.created').
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class StringKeySubscriber
    {
        public function subscribe($events): array
        {
            return [
                'eloquent.*' => 'handleAny',
                'user.created' => fn ($e) => null,
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toHaveCount(1);
    expect($classes[0]['closureHandles'][0]['event'])->toBe('user.created');
});

it('discovers imperative $events->listen() calls with a bare-string method (binds to self)', function () {
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
    expect($classes[0]['handles'])->toBe([
        ['event' => 'event', 'method' => 'handler'],
    ]);
    expect($classes[0]['closureHandles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative [self::class, method] tuples to handles[]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative [static::class, method] tuples to handles[]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, [static::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative [OwnSubscriber::class, method] tuples (by own FQCN) to handles[]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, [ImperativeSubscriber::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative bare-string method to handles[] (binds to self)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, 'onPlaced');
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative bare self::class (no tuple) to handles[] with method handle', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, self::class);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
    ]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('routes imperative [ForeignClass::class, method] tuples to foreignPairs[] (not handles)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Support\AuditLogger;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, [AuditLogger::class, 'log']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Support\\AuditLogger', 'method' => 'log'],
    ]);
});

it('routes imperative bare ForeignClass::class (no tuple) to foreignPairs[] with method handle', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Support\AuditLogger;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, AuditLogger::class);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'listener' => 'App\\Support\\AuditLogger', 'method' => 'handle'],
    ]);
});

it('routes imperative short-closure listeners to closureHandles[]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, fn ($e) => null);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toHaveCount(1);
    expect($classes[0]['closureHandles'][0]['event'])->toBe('App\\Events\\OrderPlaced');
    expect($classes[0]['closureHandles'][0]['line'])->toBeInt()->toBeGreaterThan(0);
});

it('routes imperative long-form closure listeners to closureHandles[]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, function ($e) {
                return null;
            });
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toHaveCount(1);
    expect($classes[0]['closureHandles'][0]['event'])->toBe('App\\Events\\OrderPlaced');
});

it('accepts a string event with imperative tuple listener', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen('user.created', [self::class, 'onUserCreated']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'user.created', 'method' => 'onUserCreated'],
    ]);
});

it('drops imperative calls whose event arg is a variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen($eventClass, [self::class, 'handler']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('drops imperative calls whose listener tuple method is a variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, [self::class, $method]);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('drops imperative calls whose listener is a bare variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ImperativeSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, $listener);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('unions both return-array and imperative discoveries on the same subscriber', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class MixedFormSubscriber
    {
        public function subscribe($events): array
        {
            $events->listen(StockLow::class, [self::class, 'onStockLow']);

            return [
                OrderPlaced::class => 'handleOrderPlaced',
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toContain(
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handleOrderPlaced'],
    );
    expect($classes[0]['handles'])->toContain(
        ['event' => 'App\\Events\\StockLow', 'method' => 'onStockLow'],
    );
});

it('binds the dispatcher to whatever name the first parameter uses', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class RenamedParamSubscriber
    {
        public function subscribe($dispatcher): void
        {
            $dispatcher->listen(OrderPlaced::class, [self::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('discovers imperative calls even when the first parameter has no type hint', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class UntypedParamSubscriber
    {
        public function subscribe($events): void
        {
            $events->listen(OrderPlaced::class, 'onPlaced');
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('skips imperative discovery when subscribe() has zero parameters but still parses return-array', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ZeroParamSubscriber
    {
        public function subscribe(): array
        {
            return [
                OrderPlaced::class => 'onPlaced',
            ];
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('drops calls on a receiver that is not the bound dispatcher variable', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class WrongReceiverSubscriber
    {
        public function subscribe($events): void
        {
            $this->dispatcher->listen(OrderPlaced::class, [self::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('does not recurse into nested closures even when the receiver matches the param name', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class NestedClosureSubscriber
    {
        public function subscribe($events): void
        {
            $callback = fn () => $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});

it('captures imperative calls inside an if statement', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class IfSubscriber
    {
        public function subscribe($events): void
        {
            if ($x) {
                $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
            }
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('captures imperative calls inside a foreach', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class ForeachSubscriber
    {
        public function subscribe($events): void
        {
            foreach ($items as $item) {
                $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
            }
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ]);
});

it('captures imperative calls inside a try/catch', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class TryCatchSubscriber
    {
        public function subscribe($events): void
        {
            try {
                $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
            } catch (\Throwable $e) {
                $events->listen(StockLow::class, [self::class, 'onStockLow']);
            }
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toContain(
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    );
    expect($classes[0]['handles'])->toContain(
        ['event' => 'App\\Events\\StockLow', 'method' => 'onStockLow'],
    );
});

it('ignores $events->subscribe(...) calls inside subscribe() body', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class NestedSubscribeSubscriber
    {
        public function subscribe($events): void
        {
            $events->subscribe(SomeOtherSubscriber::class);
        }
    }
    PHP;

    $classes = runSubscriberClassVisitor($source);

    expect($classes[0]['handles'])->toBe([]);
    expect($classes[0]['closureHandles'])->toBe([]);
    expect($classes[0]['foreignPairs'])->toBe([]);
});
