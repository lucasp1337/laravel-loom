<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds `Model::observe(Observer::class)` calls. Tracks enclosing class so
 * `self::` / `static::` resolve to the declaring class; `parent::` is skipped.
 */
final class ObserveCallVisitor extends NodeVisitorAbstract
{
    /** @var array<int, string> */
    private array $classStack = [];

    /** @var array<int, array{model: string, observers: array<int, string>, line: int}> */
    private array $pairs = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classStack = [];
        $this->pairs = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->namespacedName !== null) {
                $this->classStack[] = $node->namespacedName->toString();
            } else {
                // Push a sentinel so the leaveNode pop stays balanced for
                // anonymous classes (which have no namespacedName).
                $this->classStack[] = '';
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // StaticCall handling lives in leaveNode so NameResolver has resolved
        // the Name nodes inside the call's argument expressions before we read.
        if (! $node instanceof Node\Expr\StaticCall) {
            if ($node instanceof Node\Stmt\Class_) {
                array_pop($this->classStack);
            }

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
            $model = end($this->classStack);
            if ($model === false || $model === '') {
                return null;
            }
        } elseif ($lowered === 'parent') {
            return null;
        } else {
            $model = $rawClass;
        }

        $firstArg = $node->args[0];
        if (! $firstArg instanceof Node\Arg) {
            return null;
        }

        $observers = AstHelpers::classConstList($firstArg->value);
        if ($observers === []) {
            return null;
        }

        $this->pairs[] = [
            'model' => $model,
            'observers' => $observers,
            'line' => $node->getStartLine(),
        ];

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
