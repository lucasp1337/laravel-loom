<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;

/**
 * Collects subscriber FQCNs from `$subscribe` on EventServiceProvider classes.
 *
 * @internal
 */
final class SubscribeArrayVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;

    /** @var array<int, string> */
    private array $subscribers = [];

    protected function reset(): void
    {
        $this->subscribers = [];
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        $this->leaveClassScope($node);

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
