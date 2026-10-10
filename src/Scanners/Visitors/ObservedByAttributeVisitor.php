<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ObserverPair;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use PhpParser\Node;

/**
 * Finds `#[ObservedBy(Observer::class)]` (and array form) on model classes.
 * The attributed class is the model, and `#[ObservedBy([A::class, B::class])]`
 * gives two registrations.
 *
 * @internal
 */
final class ObservedByAttributeVisitor extends CollectingVisitor
{
    private const OBSERVED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy';

    /** @var list<ObserverPair> */
    private array $pairs = [];

    protected function reset(): void
    {
        $this->pairs = [];
    }

    public function leaveNode(Node $node): null
    {
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
                foreach (Args::of($attr->args)->values() as $value) {
                    foreach (ClassRef::listFrom($value) as $fqcn) {
                        $observers[] = $fqcn;
                    }
                }

                if ($observers === []) {
                    continue;
                }

                $this->pairs[] = new ObserverPair(
                    model: $model,
                    observers: $observers,
                    line: $line,
                );
            }
        }

        return null;
    }

    /** @return list<ObserverPair> */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
