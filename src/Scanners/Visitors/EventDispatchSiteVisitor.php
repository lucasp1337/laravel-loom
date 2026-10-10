<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EventDispatchTarget;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;

/**
 * Collects statically resolvable event-class targets from dispatch sites.
 * Dynamic forms are handled by DispatchScanner.
 *
 * @internal
 */
final class EventDispatchSiteVisitor extends CollectingVisitor
{
    private const DISPATCH_METHODS = ['dispatch', 'dispatchIf', 'dispatchUnless'];

    /** @var list<EventDispatchTarget> */
    private array $targets = [];

    protected function reset(): void
    {
        $this->targets = [];
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

        $fn = strtolower($node->name->toString());
        if ($fn !== 'event' && $fn !== 'broadcast') {
            return;
        }

        $fqcn = $this->resolveFirstArgClass(Args::of($node->args));
        if ($fqcn !== null) {
            $this->targets[] = new EventDispatchTarget(fqcn: $fqcn, line: $node->getStartLine(), form: DispatchForm::HELPER);
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

        $method = $node->name->toString();
        if (! in_array($method, self::DISPATCH_METHODS, true)) {
            return;
        }

        $className = $node->class->toString();

        if (Facades::EVENT->matches($className)) {
            // The Event facade only has dispatch(); dispatchIf/dispatchUnless belong to Dispatchable classes.
            if ($method !== 'dispatch') {
                return;
            }

            $fqcn = $this->resolveFirstArgClass(Args::of($node->args));
            if ($fqcn !== null) {
                $this->targets[] = new EventDispatchTarget(fqcn: $fqcn, line: $node->getStartLine(), form: DispatchForm::FACADE);
            }

            return;
        }

        // X::dispatch/dispatchIf/dispatchUnless(...) — the class itself is the target.
        $this->targets[] = new EventDispatchTarget(fqcn: $className, line: $node->getStartLine(), form: DispatchForm::DISPATCHABLE);
    }

    private function resolveFirstArgClass(Args $args): ?string
    {
        return ClassRef::fromInstanceOrConstant($args->valueAt(0));
    }

    /**
     * @return list<EventDispatchTarget>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
