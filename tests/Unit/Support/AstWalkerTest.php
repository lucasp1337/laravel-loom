<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AstWalker;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

it('resolves names before the visitors see them', function (): void {
    $seen = new class extends NodeVisitorAbstract
    {
        public ?string $class = null;

        public function leaveNode(Node $node): null
        {
            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                $this->class = $node->class->toString();
            }

            return null;
        }
    };

    AstWalker::walkSource('<?php namespace App; use Lib\Thing; new Thing;', [$seen]);

    expect($seen->class)->toBe('Lib\\Thing');
});

it('returns the resolved statements', function (): void {
    $ast = AstWalker::walkSource('<?php namespace App; class A {}');

    expect($ast[0])->toBeInstanceOf(Node\Stmt\Namespace_::class);
});

it('throws on a parse error, unlike walk()', function (): void {
    AstWalker::walkSource('<?php class {');
})->throws(Error::class);
