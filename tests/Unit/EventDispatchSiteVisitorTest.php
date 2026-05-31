<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\EventDispatchTarget;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Scanners\Visitors\EventDispatchSiteVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run EventDispatchSiteVisitor (after NameResolver) over it.
 *
 * @return list<EventDispatchTarget>
 */
function runEventDispatchSiteVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EventDispatchSiteVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getTargets();
}

it('resolves event(new App\\Events\\Foo())', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Events\Foo;

    function run(): void
    {
        event(new Foo());
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toHaveCount(1);
    expect($targets[0]->fqcn)->toBe('App\\Events\\Foo');
    expect($targets[0]->form)->toBe(DispatchForm::HELPER);
});

it('resolves event(App\\Events\\Foo::class)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Events\Foo;

    function run(): void
    {
        event(Foo::class);
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toHaveCount(1);
    expect($targets[0]->fqcn)->toBe('App\\Events\\Foo');
    expect($targets[0]->form)->toBe(DispatchForm::HELPER);
});

it('resolves Event::dispatch(Foo::class) with the Event facade imported', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Events\Foo;
    use Illuminate\Support\Facades\Event;

    function run(): void
    {
        Event::dispatch(Foo::class);
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toHaveCount(1);
    expect($targets[0]->fqcn)->toBe('App\\Events\\Foo');
    expect($targets[0]->form)->toBe(DispatchForm::FACADE);
});

it('resolves App\\Events\\Foo::dispatch($payload) as a Dispatchable trait call', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Events\Foo;

    function run(mixed $payload): void
    {
        Foo::dispatch($payload);
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toHaveCount(1);
    expect($targets[0]->fqcn)->toBe('App\\Events\\Foo');
    expect($targets[0]->form)->toBe(DispatchForm::DISPATCHABLE);
});

it('ignores event($variable)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    function run(object $eventInstance): void
    {
        event($eventInstance);
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toBe([]);
});

it('ignores event with string interpolation', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    function run(string $x): void
    {
        event("App\\Events\\{$x}");
    }
    PHP;

    $targets = runEventDispatchSiteVisitor($source);

    expect($targets)->toBe([]);
});
