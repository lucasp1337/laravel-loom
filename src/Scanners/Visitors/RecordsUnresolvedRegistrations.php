<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\ExpressionText;
use PhpParser\Node;

/**
 * Collects registration forms a visitor saw but could not read, as unresolved
 * entries. Call {@see self::resetUnresolved()} from `reset()`; the scanner
 * fills `file` and promotes the records.
 *
 * @internal
 */
trait RecordsUnresolvedRegistrations
{
    /** @var list<UnresolvedDispatchRecord> */
    private array $unresolved = [];

    private function resetUnresolved(): void
    {
        $this->unresolved = [];
    }

    private function recordUnresolved(Node\Expr $expr, UnresolvedReason $reason): void
    {
        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $expr->getStartLine(),
            expression: ExpressionText::of($expr),
            reason: $reason->value,
        );
    }

    /** @return list<UnresolvedDispatchRecord> */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }
}
