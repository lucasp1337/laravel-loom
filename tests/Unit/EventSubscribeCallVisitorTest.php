<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\EventSubscribeCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, string>
 */
function runEventSubscribeCallVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EventSubscribeCallVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getSubscribers();
}

it('extracts a subscriber FQCN from Event::subscribe with the facade imported via use', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\AuditSubscriber;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::subscribe(AuditSubscriber::class);
        }
    }
    PHP;

    expect(runEventSubscribeCallVisitor($source))->toBe(['App\\Listeners\\AuditSubscriber']);
});

it('extracts a subscriber when the Event facade is referenced via its FQCN', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    class AppServiceProvider
    {
        public function boot(): void
        {
            \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\AuditSubscriber::class);
        }
    }
    PHP;

    expect(runEventSubscribeCallVisitor($source))->toBe(['App\\Listeners\\AuditSubscriber']);
});

it('skips Event::subscribe with a variable argument', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::subscribe($dynamicSubscriber);
        }
    }
    PHP;

    expect(runEventSubscribeCallVisitor($source))->toBe([]);
});

it('ignores subscribe calls on other facades', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Listeners\AuditSubscriber;
    use Illuminate\Support\Facades\Cache;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Cache::subscribe(AuditSubscriber::class);
        }
    }
    PHP;

    expect(runEventSubscribeCallVisitor($source))->toBe([]);
});
