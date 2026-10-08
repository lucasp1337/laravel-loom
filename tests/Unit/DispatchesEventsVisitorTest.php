<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\DispatchesEventsVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** @return list<Lucasp\Loom\Dto\DispatchesEventsMapping> */
function dispatchesEventsMappings(string $source): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
    expect($ast)->not->toBeNull();

    $visitor = new DispatchesEventsVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor->getMappings();
}

it('reads literal hook => Class::class pairs', function () {
    $mappings = dispatchesEventsMappings(<<<'PHP'
    <?php
    namespace App\Models;
    use App\Events\Made;
    class Thing extends \Illuminate\Database\Eloquent\Model {
        protected $dispatchesEvents = ['created' => Made::class, 'saved' => \App\Events\Kept::class];
    }
    PHP);

    expect($mappings)->toHaveCount(2);
    expect($mappings[0]->modelFqcn)->toBe('App\\Models\\Thing');
    expect($mappings[0]->hook)->toBe('created');
    expect($mappings[0]->eventFqcn)->toBe('App\\Events\\Made');
    expect($mappings[1]->eventFqcn)->toBe('App\\Events\\Kept');
});

it('skips non-literal keys and values, static properties, and other properties', function () {
    $mappings = dispatchesEventsMappings(<<<'PHP'
    <?php
    namespace App\Models;
    use App\Events\Made;
    class Thing {
        protected $dispatchesEvents = [self::HOOK => Made::class, 'a' => 'str', 'b' => $x];
        protected static $other = ['created' => Made::class];
    }
    PHP);

    expect($mappings)->toBe([]);
});
