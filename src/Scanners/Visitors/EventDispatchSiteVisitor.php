<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EventDispatchTarget;
use Lucasp\Loom\Scanners\Dispatch\DispatchRuleMatcher;
use Lucasp\Loom\Scanners\Dispatch\DispatchRules;
use Lucasp\Loom\Scanners\Dispatch\DispatchTarget;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\ClassRef;
use PhpParser\Node;

/**
 * Collects statically resolvable event-class targets from dispatch sites.
 * Dynamic forms are handled by DispatchScanner. Recognition uses the event
 * discovery subset of the dispatch rule table.
 *
 * @internal
 */
final class EventDispatchSiteVisitor extends CollectingVisitor
{
    /** @var list<EventDispatchTarget> */
    private array $targets = [];

    private DispatchRuleMatcher $matcher;

    public function __construct(?DispatchRuleMatcher $matcher = null)
    {
        $this->matcher = $matcher ?? new DispatchRuleMatcher(DispatchRules::eventDiscovery());
    }

    protected function reset(): void
    {
        $this->targets = [];
    }

    public function leaveNode(Node $node): null
    {
        $call = CallSite::of($node);
        $rule = $call !== null ? $this->matcher->match($call) : null;
        if ($call === null || $rule === null) {
            return null;
        }

        $fqcn = match ($rule->target) {
            // `event($e)`, `Event::dispatch($e)`: the first argument's class
            DispatchTarget::PENDING_ARGUMENT => ClassRef::fromInstanceOrConstant($call->args()->valueAt($rule->argIndex)),
            // `X::dispatch(...)`: the class itself
            DispatchTarget::STATIC_CLASS => $call->className(),
            DispatchTarget::ARGUMENT, DispatchTarget::LIST_ITEMS => null,
        };

        if ($fqcn !== null) {
            $this->targets[] = new EventDispatchTarget(fqcn: $fqcn, line: $call->line(), form: $rule->form);
        }

        return null;
    }

    /**
     * @return list<EventDispatchTarget>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
