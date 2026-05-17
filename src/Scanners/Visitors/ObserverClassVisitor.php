<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Enumerates Eloquent observer hook methods on classes.
 *
 * For each top-level Stmt\Class_, records every method whose name matches the
 * canonical hook enum (see docs/scanners/observers.md). Visibility is ignored
 * by design — Laravel's observer dispatcher does not strictly require public.
 */
final class ObserverClassVisitor extends NodeVisitorAbstract
{
    /**
     * Canonical Eloquent model lifecycle hook names. Mirrors
     * schema/loom-index.schema.json#/$defs/modelEvent/properties/event/enum.
     */
    public const HOOKS = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
        'replicating', 'trashed', 'forceDeleting', 'forceDeleted',
        'booting', 'booted',
    ];

    /** @var array<string, array<int, string>> fqcn => sorted unique hook names */
    private array $hooksByClass = [];

    /** @var array<int, array{fqcn: string, line: int}> */
    private array $classes = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->hooksByClass = [];
        $this->classes = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }

        if ($node->namespacedName === null) {
            return null;
        }

        $fqcn = $node->namespacedName->toString();

        $this->classes[] = [
            'fqcn' => $fqcn,
            'line' => $node->getStartLine(),
        ];

        $hooks = [];
        $hookSet = array_flip(self::HOOKS);

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $name = $stmt->name->toString();
            if (! isset($hookSet[$name])) {
                continue;
            }
            $hooks[$name] = true;
        }

        $hookList = array_keys($hooks);
        sort($hookList);

        $this->hooksByClass[$fqcn] = $hookList;

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getHooks(string $observerFqcn): array
    {
        return $this->hooksByClass[$observerFqcn] ?? [];
    }

    /**
     * @return array<int, array{fqcn: string, line: int}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
