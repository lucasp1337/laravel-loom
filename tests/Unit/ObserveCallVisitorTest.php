<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\ObserveCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, array{model: string, observers: array<int, string>, line: int}>
 */
function runObserveCallVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ObserveCallVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getPairs();
}

it('resolves Model::observe(Observer::class) outside any class body', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Models\User;
    use App\Observers\UserObserver;

    class AppServiceProvider
    {
        public function boot(): void
        {
            User::observe(UserObserver::class);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['model'])->toBe('App\\Models\\User');
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\UserObserver']);
});

it('resolves static::observe(...) to the enclosing class', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\UserObserver;

    class User
    {
        public static function booted(): void
        {
            static::observe(UserObserver::class);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['model'])->toBe('App\\Models\\User');
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\UserObserver']);
});

it('resolves self::observe(...) to the enclosing class', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\UserObserver;

    class User
    {
        public static function booted(): void
        {
            self::observe(UserObserver::class);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['model'])->toBe('App\\Models\\User');
});

it('skips parent::observe(...) calls', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\UserObserver;

    class User
    {
        public static function booted(): void
        {
            parent::observe(UserObserver::class);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('flattens an array argument to multiple observers', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Models\User;
    use App\Observers\Foo;
    use App\Observers\Bar;

    class AppServiceProvider
    {
        public function boot(): void
        {
            User::observe([Foo::class, Bar::class]);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\Foo', 'App\\Observers\\Bar']);
});

it('skips Model::observe($var) with a variable argument', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Models\User;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $observer = 'whatever';
            User::observe($observer);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toBe([]);
});

it('drops non-conforming items inside a mixed array argument', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Models\User;
    use App\Observers\Foo;
    use App\Observers\Bar;

    class AppServiceProvider
    {
        public function boot(): void
        {
            $dyn = 'x';
            User::observe([Foo::class, $dyn, Bar::class]);
        }
    }
    PHP;

    $pairs = runObserveCallVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\Foo', 'App\\Observers\\Bar']);
});
