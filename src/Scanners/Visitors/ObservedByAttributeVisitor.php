<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds `#[ObservedBy(Observer::class)]` (and array form) on model classes.
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
                    foreach (AstHelpers::classConstList($arg->value) as $fqcn) {
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
     * @return array<int, array{model: string, observers: array<int, string>, line: int}>
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
