<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ObserverPair;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use PhpParser\Node;

/**
 * Finds `Model::observe(Observer::class)` calls. Tracks enclosing class so
 * `self::` / `static::` resolve to the declaring class; `parent::` is skipped.
 *
 * @internal
 */
final class ObserveCallVisitor extends CollectingVisitor
{
    use TracksClassScope;

    /** @var list<ObserverPair> */
    private array $pairs = [];

    protected function reset(): void
    {
        $this->pairs = [];
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->leaveClassScope($node);

        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        if ($node->name->toString() !== 'observe') {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if ($node->args === []) {
            return null;
        }

        $rawClass = $node->class->toString();
        $lowered = strtolower($rawClass);

        if ($lowered === 'static' || $lowered === 'self') {
            // `static::observe()` only resolves inside a named class (not a trait or anonymous class).
            $model = $this->currentClassNode() instanceof Node\Stmt\Class_ ? $this->currentClassFqcn() : null;
            if ($model === null) {
                return null;
            }
        } elseif ($lowered === 'parent') {
            return null;
        } else {
            $model = $rawClass;
        }

        $first = Args::of($node->args)->valueAt(0);
        if ($first === null) {
            return null;
        }

        $observers = ClassRef::listFrom($first);
        if ($observers === []) {
            return null;
        }

        $this->pairs[] = new ObserverPair(
            model: $model,
            observers: $observers,
            line: $node->getStartLine(),
        );

        return null;
    }

    /** @return list<ObserverPair> */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
