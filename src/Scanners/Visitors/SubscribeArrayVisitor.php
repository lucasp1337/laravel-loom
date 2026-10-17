<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Collects subscriber FQCNs from `$subscribe` on EventServiceProvider classes.
 * Items are `Sub::class`, class-name strings or concatenations of such parts,
 * or `new Sub`; anything else is recorded as unresolved.
 *
 * @internal
 */
final class SubscribeArrayVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;
    use RecordsUnresolvedRegistrations;

    /** @var array<int, string> */
    private array $subscribers = [];

    protected function reset(): void
    {
        $this->subscribers = [];
        $this->resetUnresolved();
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
                $folded = ListenerRef::fold($item->value)
            ?? ($item->value instanceof Node\Expr\New_ ? ClassRef::fromInstanceOrConstant($item->value) : null);
                if ($folded === null || Fqcn::normalize($folded) === '') {
                    $this->recordUnresolved($item->value, UnresolvedReason::UNREADABLE_LISTENER);

                    continue;
                }

                $this->subscribers[] = Fqcn::normalize($folded);
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
