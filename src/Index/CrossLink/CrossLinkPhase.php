<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

/**
 * One step of the cross-link pass. Phases run in registration order and
 * mutate the shared {@see CrossLinkContext} in place. Adding a new relation
 * means adding a phase — no existing phase or the orchestrator changes (OCP).
 */
interface CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void;
}
