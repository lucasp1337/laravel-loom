<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\EloquentListenStringVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, array{model: string, hook: string, handler: string, method: string, line: int}>
 */
function runEloquentListenStringVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EloquentListenStringVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getEntries();
}

it('extracts a Class@method handler from a space-form event string', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.creating: App\\Models\\User', 'App\\Observers\\UserObserver@creating');
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->model)->toBe('App\\Models\\User');
    expect($entries[0]->hook)->toBe('creating');
    expect($entries[0]->handler)->toBe('App\\Observers\\UserObserver');
    expect($entries[0]->method)->toBe('creating');
});

it('extracts a no-space-form event string', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.deleted:App\\Models\\Post', 'App\\Observers\\PostObserver@deleted');
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->model)->toBe('App\\Models\\Post');
    expect($entries[0]->hook)->toBe('deleted');
});

it('extracts a tuple handler [Class::class, method]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Observers\UserObserver;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.creating: App\\Models\\User', [UserObserver::class, 'creating']);
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->handler)->toBe('App\\Observers\\UserObserver');
    expect($entries[0]->method)->toBe('creating');
});

it('defaults method to the hook name when handler is Class::class with no method', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Observers\UserObserver;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.saved: App\\Models\\User', UserObserver::class);
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->handler)->toBe('App\\Observers\\UserObserver');
    expect($entries[0]->method)->toBe('saved');
});

it('skips closure handlers', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.creating: App\\Models\\User', function ($model) {});
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toBe([]);
});

it('skips dynamic event-name arguments', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Observers\UserObserver;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $name = 'eloquent.creating: App\\Models\\User';
            Event::listen($name, UserObserver::class);
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toBe([]);
});

it('skips invalid hook names in the event string', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Observers\UserObserver;
    use Illuminate\Support\Facades\Event;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Event::listen('eloquent.invalid: App\\Models\\User', UserObserver::class);
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toBe([]);
});

it('skips static calls that are not on the Event facade', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Observers\UserObserver;
    use Illuminate\Support\Facades\Cache;

    class AppServiceProvider
    {
        public function boot(): void
        {
            Cache::listen('eloquent.creating: App\\Models\\User', UserObserver::class);
        }
    }
    PHP;

    $entries = runEloquentListenStringVisitor($source);

    expect($entries)->toBe([]);
});
