<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete notification classes from a parsed file. Mirrors
 * MailableClassVisitor and additionally extracts statically resolvable
 * `via()` channels.
 *
 * Channel extraction is limited per ADR 0003: the `via()` body must be
 * a single `return [...];` whose array items are either literal strings
 * or `Class::class` constants. Anything else flips
 * `channels_dynamic: true` and emits `channels: []`.
 *
 * Skips abstract, interface, trait, and anonymous classes.
 */
final class NotificationClassVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queue_config: array<string, string|int|null>,
     *     channels: list<string>,
     *     channels_dynamic: bool,
     * }>
     */
    private array $classes = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classes = [];

        return null;
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

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queue_config' => QueueConfig::extractFrom($node),
            'channels' => $channels,
            'channels_dynamic' => $dynamic,
        ];

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
     * Parse a `via()` method body. Returns [channels, dynamic].
     *
     * No `via()` declared → ([], false): the class declared no channels.
     * `via()` body that's not exactly `return [literal-items];` → ([], true).
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
        if (! $expr instanceof Node\Expr\Array_) {
            return [[], true];
        }

        $channels = [];
        foreach ($expr->items as $item) {
            if ($item->key !== null) {
                // Keyed entries aren't valid channel arrays at runtime — bail.
                return [[], true];
            }

            $value = $item->value;

            if ($value instanceof Node\Scalar\String_) {
                $channels[] = strtolower($value->value);

                continue;
            }

            $fqcn = AstHelpers::classConstFqcn($value);
            if ($fqcn !== null) {
                $channels[] = $fqcn;

                continue;
            }

            // Variable, method call, conditional, anything else — bail.
            return [[], true];
        }

        return [$channels, false];
    }

    /**
     * @return array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queue_config: array<string, string|int|null>,
     *     channels: list<string>,
     *     channels_dynamic: bool,
     * }>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
