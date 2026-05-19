<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\IdentifiesEventServiceProvider;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects subscriber FQCNs from `$subscribe` on EventServiceProvider classes.
 */
final class SubscribeArrayVisitor extends NodeVisitorAbstract
{
    use IdentifiesEventServiceProvider;

    /** @var array<int, string> */
    private array $subscribers = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->subscribers = [];
        $this->resetEventServiceProviderStack();

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->pushClassNode($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        $this->popClassNode($node);

        return null;
    }

    private function handleProperty(Node\Stmt\Property $node): void
    {
        if (! $this->inEventServiceProvider()) {
            return;
        }

        foreach ($node->props as $prop) {
            if ($prop->name->toString() !== 'subscribe') {
                continue;
            }
            if (! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($prop->default->items as $item) {
                $fqcn = AstHelpers::classConstFqcn($item->value);
                if ($fqcn !== null) {
                    $this->subscribers[] = $fqcn;
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }
}
