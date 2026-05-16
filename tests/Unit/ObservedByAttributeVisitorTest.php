<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\ObservedByAttributeVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @return array<int, array{model: string, observers: array<int, string>, line: int}>
 */
function runObservedByAttributeVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ObservedByAttributeVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getPairs();
}

it('extracts a single observer from a simple #[ObservedBy] attribute', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\UserObserver;
    use Illuminate\Database\Eloquent\Attributes\ObservedBy;

    #[ObservedBy(UserObserver::class)]
    class User
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['model'])->toBe('App\\Models\\User');
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\UserObserver']);
});

it('extracts multiple observers from the array form of #[ObservedBy]', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\Foo;
    use App\Observers\Bar;
    use Illuminate\Database\Eloquent\Attributes\ObservedBy;

    #[ObservedBy([Foo::class, Bar::class])]
    class Post
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['model'])->toBe('App\\Models\\Post');
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\Foo', 'App\\Observers\\Bar']);
});

it('resolves the fully qualified form of the ObservedBy attribute name', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\Foo;

    #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Foo::class)]
    class FullyQualified
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toHaveCount(1);
    expect($pairs[0]['observers'])->toBe(['App\\Observers\\Foo']);
});

it('skips #[ObservedBy] attributes whose argument is not ::class', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Attributes\ObservedBy;

    #[ObservedBy('App\\Observers\\Foo')]
    class StringArg
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toBe([]);
});

it('emits no pair when the class has no ObservedBy attribute', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    class Plain
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toBe([]);
});

it('ignores attributes with names other than ObservedBy', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\Foo;

    #[\App\Attributes\SomeOther(Foo::class)]
    class IgnoredAttr
    {
    }
    PHP;

    $pairs = runObservedByAttributeVisitor($source);

    expect($pairs)->toBe([]);
});
