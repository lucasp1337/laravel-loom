<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\ListenerClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run ListenerClassVisitor (after NameResolver) over it.
 *
 * @return array<int, array{fqcn: string, line: int, queued: bool, has_handle: bool, handles: array<int, string>}>
 */
function runListenerClassVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ListenerClassVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getClasses();
}

it('extracts a listener with a typed handle() parameter', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class SendOrderConfirmation
    {
        public function handle(OrderPlaced $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Listeners\\SendOrderConfirmation');
    expect($classes[0]['line'])->toBe(7);
    expect($classes[0]['handles'])->toBe(['App\\Events\\OrderPlaced']);
    expect($classes[0]['queued'])->toBeFalse();
    expect($classes[0]['has_handle'])->toBeTrue();
});

it('marks the listener as queued when implementing ShouldQueue via a use import', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use Illuminate\Contracts\Queue\ShouldQueue;

    class SendOrderConfirmation implements ShouldQueue
    {
        public function handle(OrderPlaced $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queued'])->toBeTrue();
    expect($classes[0]['handles'])->toBe(['App\\Events\\OrderPlaced']);
});

it('marks the listener as queued when implementing the ShouldQueue FQCN directly', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class DirectFqcnListener implements \Illuminate\Contracts\Queue\ShouldQueue
    {
        public function handle(\App\Events\OrderPlaced $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['queued'])->toBeTrue();
});

it('emits a hit for a listener class with no handle() method', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class NoHandleListener
    {
        public function somethingElse(): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeFalse();
    expect($classes[0]['handles'])->toBe([]);
});

it('records empty handles for an untyped handle() first parameter', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class UntypedListener
    {
        public function handle($event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeTrue();
    expect($classes[0]['handles'])->toBe([]);
});

it('records empty handles for a nullable handle() type-hint', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;

    class NullableListener
    {
        public function handle(?OrderPlaced $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeTrue();
    expect($classes[0]['handles'])->toBe([]);
});

it('records empty handles for a union-typed handle()', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    use App\Events\OrderPlaced;
    use App\Events\StockLow;

    class UnionListener
    {
        public function handle(OrderPlaced|StockLow $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeTrue();
    expect($classes[0]['handles'])->toBe([]);
});

it('records empty handles for a builtin-typed handle()', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Listeners;

    class BuiltinListener
    {
        public function handle(string $event): void
        {
        }
    }
    PHP;

    $classes = runListenerClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['has_handle'])->toBeTrue();
    expect($classes[0]['handles'])->toBe([]);
});
