<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\EventClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run EventClassVisitor (after NameResolver) over it.
 *
 * @return array<int, array{fqcn: string, line: int}>
 */
function runEventClassVisitor(string $source): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new EventClassVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getClasses();
}

it('extracts a single namespaced class declaration', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    class OrderPlaced
    {
    }
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Events\\OrderPlaced');
    expect($classes[0]['line'])->toBe(5);
});

it('extracts two top-level classes in a single file', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    class OrderPlaced
    {
    }

    class OrderShipped
    {
    }
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toHaveCount(2);
    expect($classes[0]['fqcn'])->toBe('App\\Events\\OrderPlaced');
    expect($classes[1]['fqcn'])->toBe('App\\Events\\OrderShipped');
});

it('skips anonymous class expressions', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    $handler = new class {
        public function handle(): void {}
    };
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toBe([]);
});

it('skips interface declarations', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    interface DomainEvent
    {
        public function name(): string;
    }
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toBe([]);
});

it('skips trait declarations', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    trait Dispatchable
    {
        public static function dispatch(): void {}
    }
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toBe([]);
});

it('includes abstract classes', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Events;

    abstract class AbstractDomainEvent
    {
        abstract public function name(): string;
    }
    PHP;

    $classes = runEventClassVisitor($source);

    expect($classes)->toHaveCount(1);
    expect($classes[0]['fqcn'])->toBe('App\\Events\\AbstractDomainEvent');
    expect($classes[0]['line'])->toBe(5);
});
