<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Tests\TestCase;
use PhpParser\Node;
use PhpParser\NodeVisitor;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Run one visitor over a PHP source string behind the production NameResolver
 * and return it, so a test reads its collected state.
 *
 * @template T of NodeVisitor
 *
 * @param  T  $visitor
 * @return T
 */
function runVisitor(NodeVisitor $visitor, string $source): NodeVisitor
{
    AstWalker::walkSource($source, [$visitor]);

    return $visitor;
}

/**
 * Parse a PHP expression string and return its root expression node.
 */
function parseExpr(string $expr): Node\Expr
{
    $ast = AstWalker::walkSource('<?php '.$expr.';');

    $stmt = $ast[0];
    expect($stmt)->toBeInstanceOf(Node\Stmt\Expression::class);

    return $stmt->expr;
}
