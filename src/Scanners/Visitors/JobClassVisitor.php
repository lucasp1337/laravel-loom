<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete job classes from a parsed file, capturing the queue-config
 * properties declared directly on the class.
 *
 * Skips:
 *  - Abstract classes
 *  - Anonymous classes (no namespacedName)
 *  - Interfaces and traits (different node types — never match Stmt\Class_)
 *
 * `queued` is true iff `Illuminate\Contracts\Queue\ShouldQueue` appears in the
 * DIRECT `implements` list (post-NameResolver). Indirect (via parent class)
 * is a documented gap — see issue #14.
 *
 * Reads on `leaveNode` so NameResolver has fully resolved every name inside
 * the class body before we inspect it.
 */
final class JobClassVisitor extends NodeVisitorAbstract
{
    private const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';

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
     *     queued: bool,
     *     has_handle: bool,
     *     queue_config: array{
     *         connection: string|int|null,
     *         queue: string|int|null,
     *         delay: string|int|null,
     *         tries: string|int|null,
     *         timeout: string|int|null,
     *         backoff: string|int|null
     *     }
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

        // Anonymous classes have no namespacedName — skip.
        if ($node->namespacedName === null) {
            return null;
        }

        if ($node->isAbstract()) {
            return null;
        }

        $queued = false;
        foreach ($node->implements as $implements) {
            if ($implements->toString() === self::SHOULD_QUEUE) {
                $queued = true;
                break;
            }
        }

        $hasHandle = false;
        $queueConfig = [
            'connection' => null,
            'queue' => null,
            'delay' => null,
            'tries' => null,
            'timeout' => null,
            'backoff' => null,
        ];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                if ($stmt->name->toString() === 'handle') {
                    $hasHandle = true;
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

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queued' => $queued,
            'has_handle' => $hasHandle,
            'queue_config' => $queueConfig,
        ];

        return null;
    }

    /**
     * Extract a literal scalar initializer. Returns null for non-scalar
     * expressions (method calls, config(), concatenation, etc.) and for
     * explicit null literals — both map to "not declared" in the index.
     */
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

        // Negative integers parse as a UnaryMinus around an Int_.
        if ($expr instanceof Node\Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\Int_) {
            return -$expr->expr->value;
        }

        // ConstFetch(null) and anything else: not statically resolvable.
        return null;
    }

    /**
     * @return array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queued: bool,
     *     has_handle: bool,
     *     queue_config: array{
     *         connection: string|int|null,
     *         queue: string|int|null,
     *         delay: string|int|null,
     *         tries: string|int|null,
     *         timeout: string|int|null,
     *         backoff: string|int|null
     *     }
     * }>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
