<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\NotificationClassRecord;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;

/**
 * Collects concrete notification classes and statically resolvable
 * `via()` channels. Non-literal `via()` bodies set channels_dynamic: true.
 *
 * @internal
 */
final class NotificationClassVisitor extends CollectingVisitor
{
    /** @var list<NotificationClassRecord> */
    private array $classes = [];

    protected function reset(): void
    {
        $this->classes = [];
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }
        if ($node->namespacedName === null) {
            return null;
        }
        if ($node->isAbstract()) {
            return null;
        }

        [$channels, $dynamic] = $this->extractChannels($this->findViaMethod($node));

        $this->classes[] = new NotificationClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queueConfig: QueueConfig::extractFrom($node),
            channels: $channels,
            channelsDynamic: $dynamic,
        );

        return null;
    }

    private function findViaMethod(Node\Stmt\Class_ $node): ?Node\Stmt\ClassMethod
    {
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'via') {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * No `via()` → ([], false). Non-literal body → ([], true).
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function extractChannels(?Node\Stmt\ClassMethod $via): array
    {
        if ($via === null) {
            return [[], false];
        }

        $stmts = $via->stmts;
        if ($stmts === null || count($stmts) !== 1) {
            return [[], true];
        }

        $only = $stmts[0];
        if (! $only instanceof Node\Stmt\Return_) {
            return [[], true];
        }

        $expr = $only->expr;
        if ($expr === null) {
            return [[], true];
        }

        $channels = ValueLists::channels($expr);
        if ($channels === null) {
            return [[], true];
        }

        return [$channels, false];
    }

    /** @return list<NotificationClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
