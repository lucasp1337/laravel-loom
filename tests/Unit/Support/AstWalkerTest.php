<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\AstWalker;
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

/** Walk `$file` and return the first class node the visitor sees. */
function firstClassNode(AstWalker $walker, string $file): ?Node\Stmt\Class_
{
    $seen = new class extends NodeVisitorAbstract
    {
        public ?Node\Stmt\Class_ $class = null;

        public function leaveNode(Node $node): null
        {
            $this->class ??= $node instanceof Node\Stmt\Class_ ? $node : null;

            return null;
        }
    };
    $walker->walk($file, [$seen]);

    return $seen->class;
}

it('parses a file once and shares the tree across walks', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'loom-ast-');
    file_put_contents($file, '<?php namespace App; class A {}');

    $walker = new AstWalker;

    expect(firstClassNode($walker, $file))->toBe(firstClassNode($walker, $file));
    unlink($file);
});

it('parses again when the file changes', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'loom-ast-');
    file_put_contents($file, '<?php class A {}');
    $walker = new AstWalker;
    $first = firstClassNode($walker, $file);

    file_put_contents($file, '<?php class B {}');
    $second = firstClassNode($walker, $file);
    unlink($file);

    expect($first?->name?->toString())->toBe('A')
        ->and($second?->name?->toString())->toBe('B');
});

it('does not keep trees when the cache budget is zero', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'loom-ast-');
    file_put_contents($file, '<?php class A {}');
    $walker = new AstWalker(0);

    expect(firstClassNode($walker, $file))->not->toBe(firstClassNode($walker, $file));
    unlink($file);
});

it('stops admitting files once the budget is spent', function (): void {
    $small = tempnam(sys_get_temp_dir(), 'loom-ast-');
    $large = tempnam(sys_get_temp_dir(), 'loom-ast-');
    file_put_contents($small, '<?php class A {}');
    file_put_contents($large, '<?php class B {} // '.str_repeat('x', 200));
    $walker = new AstWalker(100);

    $keptSmall = firstClassNode($walker, $small) === firstClassNode($walker, $small);
    $keptLarge = firstClassNode($walker, $large) === firstClassNode($walker, $large);
    unlink($small);
    unlink($large);

    expect($keptSmall)->toBeTrue()
        ->and($keptLarge)->toBeFalse();
});

it('reports a parse failure once however often the file is walked', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'loom-ast-');
    file_put_contents($file, '<?php class {');
    $walker = new AstWalker;

    expect($walker->walk($file, []))->toBeNull()
        ->and($walker->walk($file, []))->toBeNull()
        ->and($walker->skippedFiles())->toHaveCount(1);
    unlink($file);
});
