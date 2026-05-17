<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, string>
 */
function runSubscribeArrayVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new SubscribeArrayVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getSubscribers();
}

it('extracts subscriber FQCNs from $subscribe on an EventServiceProvider extender', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\OrderEventSubscriber;
    use App\Listeners\AuditSubscriber;

    class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
    {
        protected $subscribe = [
            OrderEventSubscriber::class,
            AuditSubscriber::class,
        ];
    }
    PHP;

    $subscribers = runSubscribeArrayVisitor($source);

    expect($subscribers)->toBe([
        'App\\Listeners\\OrderEventSubscriber',
        'App\\Listeners\\AuditSubscriber',
    ]);
});

it('ignores $subscribe arrays on classes that are not EventServiceProvider', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\IgnoredSubscriber;

    class RogueProvider
    {
        protected $subscribe = [
            IgnoredSubscriber::class,
        ];
    }
    PHP;

    expect(runSubscribeArrayVisitor($source))->toBe([]);
});

it('matches a class literally named EventServiceProvider even without extends', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\OrderEventSubscriber;

    class EventServiceProvider
    {
        protected $subscribe = [
            OrderEventSubscriber::class,
        ];
    }
    PHP;

    expect(runSubscribeArrayVisitor($source))->toBe(['App\\Listeners\\OrderEventSubscriber']);
});

it('skips non-class-const values in the $subscribe array', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
    {
        protected $subscribe = [
            'App\\Listeners\\StringFormSubscriber',
            \App\Listeners\OkSubscriber::class,
        ];
    }
    PHP;

    expect(runSubscribeArrayVisitor($source))->toBe(['App\\Listeners\\OkSubscriber']);
});
