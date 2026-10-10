<?php

declare(strict_types=1);

use Lucasp\Loom\Tests\TestCase;
use PhpParser\Node;
use PhpParser\ParserFactory;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Parse a PHP expression string and return its root expression node.
 */
function parseExpr(string $expr): Node\Expr
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse('<?php '.$expr.';');

    expect($ast)->not->toBeNull();

    $stmt = $ast[0];
    expect($stmt)->toBeInstanceOf(Node\Stmt\Expression::class);

    return $stmt->expr;
}
