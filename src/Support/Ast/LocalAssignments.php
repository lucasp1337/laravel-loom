<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The values a function body assigns to its local variables, so a variable
 * used as a call argument can be traced back to the single expression that set
 * it. Nested functions and classes have their own variables and are not entered.
 *
 * A variable has a {@see self::singleValue()} only when it is assigned exactly
 * once, by plain `=`, and is not a parameter, a by-reference closure capture, a
 * `foreach` / `catch` / `global` / `static` variable, a destructuring target or
 * the operand of `unset`, `.=`, `??=` and the like. Anything else is unknown.
 *
 * @internal
 */
final class LocalAssignments
{
    /**
     * @param  array<string, list<Node\Expr>>  $values  plain `=` right-hand sides by variable name
     * @param  array<string, true>  $other  names written in any other way
     */
    private function __construct(private array $values, private array $other) {}

    public static function of(Node\FunctionLike $function): self
    {
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var array<string, list<Node\Expr>> */
            public array $values = [];

            /** @var array<string, true> */
            public array $other = [];

            public function enterNode(Node $node): ?int
            {
                // A nested function or class owns its variables; a by-reference capture can rewrite ours
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $use) {
                        if ($use->byRef && is_string($use->var->name)) {
                            $this->other[$use->var->name] = true;
                        }
                    }

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Expr\ArrowFunction
                    || $node instanceof Node\Stmt\ClassLike
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassMethod
                ) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                $this->read($node);

                return null;
            }

            private function read(Node $node): void
            {
                // `$x = <expr>`: a plain assignment; destructuring targets are "other"
                if ($node instanceof Node\Expr\Assign) {
                    if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                        $this->values[$node->var->name][] = $node->expr;
                    } else {
                        $this->markTargets($node->var);
                    }

                    return;
                }

                // `$x .= ...`, `$x ??= ...`, `$x = &$y`
                if ($node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef) {
                    $this->markTargets($node->var);

                    return;
                }

                // `foreach ($a as $k => $v)`
                if ($node instanceof Node\Stmt\Foreach_) {
                    $this->markTargets($node->valueVar);
                    if ($node->keyVar !== null) {
                        $this->markTargets($node->keyVar);
                    }

                    return;
                }

                // `catch (E $e)`
                if ($node instanceof Node\Stmt\Catch_ && $node->var !== null) {
                    $this->markTargets($node->var);

                    return;
                }

                // `unset($x)`, `global $x`, `static $x`
                $written = match (true) {
                    $node instanceof Node\Stmt\Unset_ => $node->vars,
                    $node instanceof Node\Stmt\Global_ => $node->vars,
                    $node instanceof Node\Stmt\StaticVar => [$node->var],
                    default => [],
                };
                foreach ($written as $target) {
                    $this->markTargets($target);
                }
            }

            private function markTargets(Node $target): void
            {
                if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
                    $this->other[$target->name] = true;

                    return;
                }
                if ($target instanceof Node\Expr\ArrayDimFetch) {
                    // `$x[] = ...` rewrites the array held in $x
                    $this->markTargets($target->var);

                    return;
                }
                if ($target instanceof Node\Expr\Array_) {
                    foreach ($target->items as $item) {
                        $this->markTargets($item->value);
                    }
                }
            }
        };

        $traverser = new NodeTraverser($collector);
        $body = $function instanceof Node\Expr\ArrowFunction ? [$function->expr] : ($function->getStmts() ?? []);
        $traverser->traverse($body);

        $other = $collector->other;
        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $other[$param->var->name] = true;
            }
        }

        return new self($collector->values, $other);
    }

    /** The one expression assigned to $name, or null when it is assigned zero times, repeatedly, or written another way. */
    public function singleValue(string $name): ?Node\Expr
    {
        if (isset($this->other[$name])) {
            return null;
        }

        $values = $this->values[$name] ?? [];

        return count($values) === 1 ? $values[0] : null;
    }
}
