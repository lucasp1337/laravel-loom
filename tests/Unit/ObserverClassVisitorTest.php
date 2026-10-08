<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

function runObserverClassVisitor(string $source): ObserverClassVisitor
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($source);

    expect($ast)->not->toBeNull();

    $visitor = new ObserverClassVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return $visitor;
}

it('records each named class with its line', function () {
    $visitor = runObserverClassVisitor(<<<'PHP'
    <?php

    namespace App\Observers;

    class First
    {
    }

    class Second
    {
    }
    PHP);

    expect(array_map(fn ($c): string => $c->fqcn.'@'.$c->line, $visitor->getClasses()))
        ->toBe(['App\\Observers\\First@5', 'App\\Observers\\Second@9']);
});

it('skips anonymous classes', function () {
    $visitor = runObserverClassVisitor(<<<'PHP'
    <?php

    $observer = new class {
        public function created($model): void {}
    };
    PHP);

    expect($visitor->getClasses())->toBe([]);
});
