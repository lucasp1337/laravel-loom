<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP source string and run ListenerClassVisitor (after NameResolver) over it.
 *
 * @return list<Lucasp\Loom\Dto\ListenerClassRecord>
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

it('records each named class with its line', function () {
    $classes = runListenerClassVisitor(<<<'PHP'
    <?php

    namespace App\Listeners;

    class First
    {
    }

    class Second
    {
    }
    PHP);

    expect(array_map(fn ($c): string => $c->fqcn.'@'.$c->line, $classes))
        ->toBe(['App\\Listeners\\First@5', 'App\\Listeners\\Second@9']);
});

it('marks a class that declares ShouldQueue as queued', function (string $implements) {
    $classes = runListenerClassVisitor(<<<PHP
    <?php

    namespace App\Listeners;

    use Illuminate\Contracts\Queue\ShouldQueue;

    class Queued implements {$implements}
    {
    }
    PHP);

    expect($classes[0]->queued)->toBeTrue();
})->with(['imported name' => ['ShouldQueue'], 'fqcn' => ['\\Illuminate\\Contracts\\Queue\\ShouldQueue']]);

it('skips anonymous classes', function () {
    $classes = runListenerClassVisitor(<<<'PHP'
    <?php

    $listener = new class {
        public function handle($event): void {}
    };
    PHP);

    expect($classes)->toBe([]);
});
