<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\DispatchesEventsMapping;
use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects `protected $dispatchesEvents = ['created' => Foo::class]` mappings.
 * Entries need a literal string key and a `Foo::class` value; anything else is skipped.
 *
 * @internal
 */
final class DispatchesEventsVisitor extends NodeVisitorAbstract
{
    private const PROPERTY = 'dispatchesEvents';

    /** @var list<DispatchesEventsMapping> */
    private array $mappings = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->mappings = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_ || $node->namespacedName === null) {
            return null;
        }

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Property || $stmt->isStatic()) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                if ($prop->name->toString() === self::PROPERTY && $prop->default instanceof Node\Expr\Array_) {
                    $this->collect($node->namespacedName->toString(), $prop->default);
                }
            }
        }

        return null;
    }

    private function collect(string $modelFqcn, Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            $hook = AstHelpers::scalarString($item->key);
            $event = AstHelpers::classConstFqcn($item->value);

            if ($hook === null || $event === null) {
                continue;
            }

            $this->mappings[] = new DispatchesEventsMapping($modelFqcn, $hook, $event, $item->getStartLine());
        }
    }

    /** @return list<DispatchesEventsMapping> */
    public function getMappings(): array
    {
        return $this->mappings;
    }
}
