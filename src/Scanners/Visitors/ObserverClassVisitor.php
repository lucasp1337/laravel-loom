<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassRecord;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Records observer classes and their matching Eloquent hook methods.
 */
final class ObserverClassVisitor extends NodeVisitorAbstract
{
    /** Mirrors schema modelEvent/event enum. */
    public const HOOKS = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
        'replicating', 'trashed', 'forceDeleting', 'forceDeleted',
        'booting', 'booted',
    ];

    /** @var array<string, list<string>> */
    private array $hooksByClass = [];

    /** @var list<ClassRecord> */
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

        $this->classes[] = new ClassRecord(fqcn: $fqcn, line: $node->getStartLine());

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

    /** @return list<string> */
    public function getHooks(string $observerFqcn): array
    {
        return $this->hooksByClass[$observerFqcn] ?? [];
    }

    /** @return list<ClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
