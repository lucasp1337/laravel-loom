<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\Ast\Literal;
use PhpParser\Node;

/**
 * Reads the `$observables` property of each named class: the custom model
 * events an observer may handle beside the Eloquent ones. Only the string
 * literal items of an array default are read.
 *
 * @internal
 */
final class ObservablesVisitor extends CollectingVisitor
{
    /** @var array<string, list<string>> class FQCN => declared custom events */
    private array $observables = [];

    protected function reset(): void
    {
        $this->observables = [];
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_ || $node->namespacedName === null) {
            return null;
        }

        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'observables' && $prop->default instanceof Node\Expr\Array_) {
                    $this->observables[$node->namespacedName->toString()] = Literal::stringItems($prop->default);
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function getObservables(): array
    {
        return $this->observables;
    }
}
