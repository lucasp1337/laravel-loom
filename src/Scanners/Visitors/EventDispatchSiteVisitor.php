<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects statically resolvable event-class targets from dispatch sites.
 * Dynamic forms are handled by DispatchScanner.
 */
final class EventDispatchSiteVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{fqcn: string, line: int, form: 'helper'|'facade'|'dispatchable'}> */
    private array $targets = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->targets = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        }

        return null;
    }

    private function handleFuncCall(Node\Expr\FuncCall $node): void
    {
        if (! $node->name instanceof Node\Name) {
            return;
        }

        if (strtolower($node->name->toString()) !== 'event') {
            return;
        }

        $fqcn = $this->resolveFirstArgClass($node->args);
        if ($fqcn !== null) {
            $this->targets[] = ['fqcn' => $fqcn, 'line' => $node->getStartLine(), 'form' => 'helper'];
        }
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }

        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        if ($node->name->toString() !== 'dispatch') {
            return;
        }

        $className = $node->class->toString();

        if (Facades::EVENT->matches($className)) {
            $fqcn = $this->resolveFirstArgClass($node->args);
            if ($fqcn !== null) {
                $this->targets[] = ['fqcn' => $fqcn, 'line' => $node->getStartLine(), 'form' => 'facade'];
            }

            return;
        }

        // X::dispatch(...) — the class itself is the target.
        $this->targets[] = ['fqcn' => $className, 'line' => $node->getStartLine(), 'form' => 'dispatchable'];
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function resolveFirstArgClass(array $args): ?string
    {
        $first = $args[0] ?? null;
        if (! $first instanceof Node\Arg) {
            return null;
        }

        return AstHelpers::resolveStaticClass($first->value);
    }

    /**
     * @return array<int, array{fqcn: string, line: int, form: 'helper'|'facade'|'dispatchable'}>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
