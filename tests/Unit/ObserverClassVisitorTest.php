<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\Visitors\ObserverClassVisitor;
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

it('extracts only canonical hook methods from a mixed class', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    class UserObserver
    {
        public function creating($model): void {}
        public function updated($model): void {}
        public function notify($model): void {}
    }
    PHP;

    $visitor = runObserverClassVisitor($source);

    expect($visitor->getClasses())->toHaveCount(1);
    expect($visitor->getClasses()[0]['fqcn'])->toBe('App\\Observers\\UserObserver');
    expect($visitor->getHooks('App\\Observers\\UserObserver'))->toBe(['creating', 'updated']);
});

it('counts hook methods regardless of visibility', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    class VisibilityObserver
    {
        private function creating($model): void {}
        protected function updated($model): void {}
        public function deleted($model): void {}
    }
    PHP;

    $visitor = runObserverClassVisitor($source);

    $hooks = $visitor->getHooks('App\\Observers\\VisibilityObserver');
    expect($hooks)->toContain('creating');
    expect($hooks)->toContain('updated');
    expect($hooks)->toContain('deleted');
    expect($hooks)->toHaveCount(3);
});

it('returns an empty hooks array for a class without hook-named methods', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    class EmptyObserver
    {
        public function notAHook(): void {}
        public function alsoNotAHook(): void {}
    }
    PHP;

    $visitor = runObserverClassVisitor($source);

    expect($visitor->getClasses())->toHaveCount(1);
    expect($visitor->getHooks('App\\Observers\\EmptyObserver'))->toBe([]);
});

it('indexes two classes in the same file independently', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    class FirstObserver
    {
        public function creating($model): void {}
    }

    class SecondObserver
    {
        public function deleted($model): void {}
        public function restored($model): void {}
    }
    PHP;

    $visitor = runObserverClassVisitor($source);

    expect($visitor->getClasses())->toHaveCount(2);
    expect($visitor->getHooks('App\\Observers\\FirstObserver'))->toBe(['creating']);
    expect($visitor->getHooks('App\\Observers\\SecondObserver'))->toBe(['deleted', 'restored']);
});

it('matches hook names case-sensitively (forceDeleted vs forcedeleted)', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    class CaseObserver
    {
        public function forceDeleted($model): void {}
        public function forcedeleted($model): void {}
        public function CREATING($model): void {}
    }
    PHP;

    $visitor = runObserverClassVisitor($source);

    // Only the canonical-spelling method is counted.
    expect($visitor->getHooks('App\\Observers\\CaseObserver'))->toBe(['forceDeleted']);
});

it('skips anonymous classes', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Observers;

    $x = new class {
        public function creating($model): void {}
    };
    PHP;

    $visitor = runObserverClassVisitor($source);

    expect($visitor->getClasses())->toBe([]);
});
