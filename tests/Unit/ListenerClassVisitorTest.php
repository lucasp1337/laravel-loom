<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\ListenerClassRecord;
use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;

/**
 * Parse a PHP source string and run ListenerClassVisitor (after NameResolver) over it.
 *
 * @return list<ListenerClassRecord>
 */
function runListenerClassVisitor(string $source): array
{
    $visitor = runVisitor(new ListenerClassVisitor, $source);

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
