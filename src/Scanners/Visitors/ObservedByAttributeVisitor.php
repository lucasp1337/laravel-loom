<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds `#[ObservedBy(Observer::class)]` (and array form) on model classes.
 *
 * Only `::class` arguments are statically resolvable; non-class-const args
 * (strings, variables) are skipped per the design's known gap.
 */
final class ObservedByAttributeVisitor extends NodeVisitorAbstract
{
    private const OBSERVED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy';

    /** @var array<int, array{model: string, observers: array<int, string>, line: int}> */
    private array $pairs = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->pairs = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Read on leaveNode so NameResolver has already rewritten Name nodes
        // inside attribute arguments to their fully qualified forms.
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }
        if ($node->namespacedName === null) {
            return null;
        }

        $model = $node->namespacedName->toString();
        $line = $node->getStartLine();

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() !== self::OBSERVED_BY) {
                    continue;
                }

                $observers = [];
                foreach ($attr->args as $arg) {
                    foreach ($this->extractObservers($arg->value) as $fqcn) {
                        $observers[] = $fqcn;
                    }
                }

                if ($observers === []) {
                    continue;
                }

                $this->pairs[] = [
                    'model' => $model,
                    'observers' => $observers,
                    'line' => $line,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractObservers(Node\Expr $value): array
    {
        $direct = $this->classConstFqcn($value);
        if ($direct !== null) {
            return [$direct];
        }

        if ($value instanceof Node\Expr\Array_) {
            $out = [];
            foreach ($value->items as $item) {
                $fqcn = $this->classConstFqcn($item->value);
                if ($fqcn !== null) {
                    $out[] = $fqcn;
                }
            }

            return $out;
        }

        return [];
    }

    private function classConstFqcn(Node\Expr $expr): ?string
    {
        if (! $expr instanceof Node\Expr\ClassConstFetch) {
            return null;
        }
        if (! $expr->class instanceof Node\Name) {
            return null;
        }
        if (! $expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($expr->name->toString() !== 'class') {
            return null;
        }

        return $expr->class->toString();
    }

    /**
     * @return array<int, array{model: string, observers: array<int, string>, line: int}>
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
