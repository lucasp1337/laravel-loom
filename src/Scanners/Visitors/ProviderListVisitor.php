<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads the service provider class names a provider list file returns:
 * `bootstrap/providers.php` (a returned list) or `config/app.php` (the
 * `providers` key of the returned array). Only the file's top-level `return`
 * counts.
 *
 * @internal
 */
final class ProviderListVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $providers = [];

    private int $depth = 0;

    public function beforeTraverse(array $nodes): ?array
    {
        $this->providers = [];
        $this->depth = 0;

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            $this->depth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            $this->depth--;

            return null;
        }

        if ($this->depth === 0 && $node instanceof Node\Stmt\Return_ && $node->expr !== null) {
            $this->collect($this->providersExpression($node->expr));
        }

        return null;
    }

    /** @return list<string> */
    public function getProviders(): array
    {
        return $this->providers;
    }

    private function providersExpression(Node\Expr $returned): Node\Expr
    {
        if ($returned instanceof Node\Expr\Array_) {
            foreach ($returned->items as $item) {
                if (AstHelpers::scalarString($item->key) === 'providers') {
                    return $item->value;
                }
            }
        }

        return $returned;
    }

    private function collect(Node\Expr $expression): void
    {
        foreach ((new NodeFinder)->findInstanceOf($expression, Node\Expr\ClassConstFetch::class) as $fetch) {
            $fqcn = AstHelpers::classConstFqcn($fetch);
            if ($fqcn !== null) {
                $this->providers[] = $fqcn;
            }
        }
    }
}
