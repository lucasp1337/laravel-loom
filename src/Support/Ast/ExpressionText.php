<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Pretty-prints an expression to one line of source for `expression` fields.
 *
 * @internal
 */
final class ExpressionText
{
    private static ?Standard $printer = null;

    public static function of(Node\Expr $expr): string
    {
        // A closure body is never the point; show its signature only.
        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) {
            $params = collect($expr->params)->map(fn (Node\Param $param): string => (self::$printer ??= new Standard)->prettyPrint([$param]))->implode(', ');

            return $expr instanceof Node\Expr\Closure ? "function ({$params}) { ... }" : "fn ({$params}) => ...";
        }

        return preg_replace('/\s*\R\s*/', ' ', (self::$printer ??= new Standard)->prettyPrintExpr($expr)) ?? '';
    }
}
