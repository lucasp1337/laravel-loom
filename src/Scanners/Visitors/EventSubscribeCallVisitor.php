<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\Facades;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Collects subscriber FQCNs from Event::subscribe(...) static calls. The
 * argument is `Sub::class`, a class-name string or concatenation of such
 * parts, or `new Sub`; anything else is recorded as unresolved.
 *
 * @internal
 */
final class EventSubscribeCallVisitor extends CollectingVisitor
{
    use RecordsUnresolvedRegistrations;

    /** @var array<int, string> */
    private array $subscribers = [];

    protected function reset(): void
    {
        $this->subscribers = [];
        $this->resetUnresolved();
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if (! Facades::EVENT->matches($node->class->toString())) {
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

        $argument = Args::of($node->args)->valueAt(0);
        if ($argument === null) {
            return null;
        }

        $folded = ListenerRef::fold($argument)
            ?? ($argument instanceof Node\Expr\New_ ? ClassRef::fromInstanceOrConstant($argument) : null);
        $fqcn = $folded === null ? null : Fqcn::normalize($folded);
        if ($fqcn === null || $fqcn === '') {
            $this->recordUnresolved($argument, UnresolvedReason::UNREADABLE_LISTENER);

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
