<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds `Event::listen('eloquent.{hook}: {Model}', $handler)` calls and
 * emits model-event entries directly (no observer-entry inference).
 *
 * Closure / dynamic handlers are skipped — emitting `"<closure>"` is noise.
 */
final class EloquentListenStringVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{model: string, hook: string, handler: string, method: string, line: int}> */
    private array $entries = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->entries = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if ($node->class->toString() !== Facades::EVENT->value) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        if ($node->name->toString() !== 'listen') {
            return null;
        }
        if (count($node->args) < 2) {
            return null;
        }

        $eventArg = $node->args[0];
        $handlerArg = $node->args[1];
        if (! $eventArg instanceof Node\Arg || ! $handlerArg instanceof Node\Arg) {
            return null;
        }

        if (! $eventArg->value instanceof Node\Scalar\String_) {
            return null;
        }

        $raw = $eventArg->value->value;
        if (! preg_match('/^eloquent\.([a-zA-Z]+):\s*(.+)$/', $raw, $matches)) {
            return null;
        }
        $hook = $matches[1];
        $model = trim($matches[2]);

        if ($model === '') {
            return null;
        }
        if (! in_array($hook, ObserverClassVisitor::HOOKS, true)) {
            return null;
        }

        $resolved = $this->resolveHandler($handlerArg->value, $hook);
        if ($resolved === null) {
            return null;
        }

        $this->entries[] = [
            'model' => ltrim($model, '\\'),
            'hook' => $hook,
            'handler' => $resolved['handler'],
            'method' => $resolved['method'],
            'line' => $node->getStartLine(),
        ];

        return null;
    }

    /**
     * @return array{handler: string, method: string}|null
     */
    private function resolveHandler(Node\Expr $value, string $defaultMethod): ?array
    {
        if ($value instanceof Node\Scalar\String_) {
            $raw = $value->value;
            $atPos = strpos($raw, '@');
            if ($atPos === false) {
                return null;
            }

            $handler = ltrim(substr($raw, 0, $atPos), '\\');
            $method = substr($raw, $atPos + 1);
            if ($handler === '' || $method === '') {
                return null;
            }

            return ['handler' => $handler, 'method' => $method];
        }

        if ($value instanceof Node\Expr\ClassConstFetch) {
            $fqcn = $this->classConstFqcn($value);
            if ($fqcn === null) {
                return null;
            }

            return ['handler' => $fqcn, 'method' => $defaultMethod];
        }

        if ($value instanceof Node\Expr\Array_) {
            if (count($value->items) < 2) {
                return null;
            }
            $first = $value->items[0];
            $second = $value->items[1];
            $handler = $this->classConstFqcn($first->value);
            if ($handler === null) {
                return null;
            }
            if (! $second->value instanceof Node\Scalar\String_) {
                return null;
            }
            $method = $second->value->value;
            if ($method === '') {
                return null;
            }

            return ['handler' => $handler, 'method' => $method];
        }

        return null;
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
     * @return array<int, array{model: string, hook: string, handler: string, method: string, line: int}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
