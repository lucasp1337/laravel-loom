<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects subscriber FQCNs from Event::subscribe(...) static calls.
 */
final class EventSubscribeCallVisitor extends NodeVisitorAbstract
{
    /** @var array<int, string> */
    private array $subscribers = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->subscribers = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if ($node->class->toString() !== Facades::EVENT->value) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        if ($node->name->toString() !== 'subscribe') {
            return null;
        }
        if (count($node->args) < 1) {
            return null;
        }

        $first = $node->args[0];
        if (! $first instanceof Node\Arg) {
            return null;
        }

        $fqcn = AstHelpers::classConstFqcn($first->value);
        if ($fqcn === null) {
            return null;
        }

        $this->subscribers[] = $fqcn;

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }
}
