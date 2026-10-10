<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;

function runObserverClassVisitor(string $source): ObserverClassVisitor
{
    $visitor = runVisitor(new ObserverClassVisitor, $source);

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
