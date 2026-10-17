<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\NotificationClassRecord;
use Lucasp\Loom\Support\Ast\ViaChannels;
use PhpParser\Node;

/**
 * Collects concrete notification classes and statically resolvable
 * `via()` channels (see {@see ViaChannels}). Unreadable and conditional
 * `via()` bodies set channels_dynamic: true. Only a `via()` declared on the
 * class itself is read.
 *
 * @implements ClassRecordVisitor<NotificationClassRecord>
 *
 * @internal
 */
final class NotificationClassVisitor extends CollectingVisitor implements ClassRecordVisitor
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
     * No `via()` gives ([], false). An unreadable body gives ([], true). A
     * conditional one gives the union of its channels and true: the list may
     * hold channels that do not apply on every call.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function extractChannels(?Node\Stmt\ClassMethod $via): array
    {
        if ($via === null) {
            return [[], false];
        }

        $read = ViaChannels::read($via);
        if ($read === null) {
            return [[], true];
        }

        return [$read['channels'], $read['conditional']];
    }

    /** @return list<NotificationClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
