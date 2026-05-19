<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete notification classes from a parsed file. Mirrors
 * MailableClassVisitor and additionally extracts statically resolvable
 * `via()` channels.
 *
 * Channel extraction is limited per ADR 0003: the `via()` body must be a
 * single `return [...];` whose array items are either literal strings or
 * `Class::class` constants. Anything else flips `channels_dynamic: true`
 * and emits `channels: []`.
 *
 * Skips abstract, interface, trait, and anonymous classes.
 */
final class NotificationClassVisitor extends NodeVisitorAbstract
{
    private const QUEUE_CONFIG_PROPERTIES = [
        'connection',
        'queue',
        'delay',
        'tries',
        'timeout',
        'backoff',
    ];

    /**
     * @var array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queue_config: array{
     *         connection: string|int|null,
     *         queue: string|int|null,
     *         delay: string|int|null,
     *         tries: string|int|null,
     *         timeout: string|int|null,
     *         backoff: string|int|null
     *     },
     *     channels: list<string>,
     *     channels_dynamic: bool
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

        $queueConfig = [
            'connection' => null,
            'queue' => null,
            'delay' => null,
            'tries' => null,
            'timeout' => null,
            'backoff' => null,
        ];

        $viaMethod = null;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                if ($stmt->name->toString() === 'via') {
                    $viaMethod = $stmt;
                }

                continue;
            }

            if (! $stmt instanceof Node\Stmt\Property) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                if (! in_array($name, self::QUEUE_CONFIG_PROPERTIES, true)) {
                    continue;
                }

                $value = $this->extractScalar($prop->default);
                if ($value !== null) {
                    $queueConfig[$name] = $value;
                }
            }
        }

        [$channels, $dynamic] = $this->extractChannels($viaMethod);

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queue_config' => $queueConfig,
            'channels' => $channels,
            'channels_dynamic' => $dynamic,
        ];

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
        if ($stmts === null) {
            // Abstract method declaration — no body to analyse.
            return [[], true];
        }

        if (count($stmts) !== 1) {
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

            if ($value instanceof Node\Expr\ClassConstFetch
                && $value->class instanceof Node\Name
                && $value->name instanceof Node\Identifier
                && $value->name->toString() === 'class'
            ) {
                $channels[] = $value->class->toString();

                continue;
            }

            // Variable, method call, conditional, anything else — bail.
            return [[], true];
        }

        return [$channels, false];
    }

    private function extractScalar(?Node\Expr $expr): string|int|null
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\Int_) {
            return -$expr->expr->value;
        }

        return null;
    }

    /**
     * @return array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queue_config: array{
     *         connection: string|int|null,
     *         queue: string|int|null,
     *         delay: string|int|null,
     *         tries: string|int|null,
     *         timeout: string|int|null,
     *         backoff: string|int|null
     *     },
     *     channels: list<string>,
     *     channels_dynamic: bool
     * }>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
