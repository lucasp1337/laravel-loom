<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EloquentListenRecord;
use Lucasp\Loom\Index\ModelHook;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Facades;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Emits model-event entries from `Event::listen('eloquent.{hook}: {Model}', $handler)`.
 *
 * The first argument is a literal string with or without the space after the
 * colon, and the hook must be one of the model hooks (booting and booted
 * included). The handler is `'Class@method'`, `[Class::class, 'method']`, or a
 * bare `Class::class` whose method defaults to the hook name; closures and
 * dynamic arguments are skipped. These entries feed `model_events[]` only: a
 * handler is never promoted to `observers[]`, because it may not be an
 * observer class.
 *
 * @internal
 */
final class EloquentListenStringVisitor extends CollectingVisitor
{
    /** @var list<EloquentListenRecord> */
    private array $entries = [];

    protected function reset(): void
    {
        $this->entries = [];
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if (! Facades::EVENT->matches($node->class->toString())) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        if ($node->name->toString() !== 'listen') {
            return null;
        }
        $args = Args::of($node->args);
        if ($args->count() < 2) {
            return null;
        }

        $eventValue = $args->valueAt(0);
        $handlerValue = $args->valueAt(1);
        if ($eventValue === null || $handlerValue === null) {
            return null;
        }

        if (! $eventValue instanceof Node\Scalar\String_) {
            return null;
        }

        $raw = $eventValue->value;
        if (! preg_match('/^eloquent\.([a-zA-Z]+):\s*(.+)$/', $raw, $matches)) {
            return null;
        }
        $hook = $matches[1];
        $model = trim($matches[2]);

        if ($model === '') {
            return null;
        }
        if (ModelHook::tryFrom($hook) === null) {
            return null;
        }

        $resolved = $this->resolveHandler($handlerValue, $hook);
        if ($resolved === null) {
            return null;
        }

        $this->entries[] = new EloquentListenRecord(
            model: Fqcn::normalize($model),
            hook: $hook,
            handler: $resolved['handler'],
            method: $resolved['method'],
            line: $node->getStartLine(),
        );

        return null;
    }

    /**
     * @return array{handler: string, method: string}|null
     */
    private function resolveHandler(Node\Expr $value, string $defaultMethod): ?array
    {
        if ($value instanceof Node\Scalar\String_) {
            $raw = $value->value;
            $parts = Fqcn::splitAtMember($raw);
            if ($parts === null) {
                return null;
            }

            [$class, $method] = $parts;
            $handler = Fqcn::normalize($class);
            if ($handler === '' || $method === '') {
                return null;
            }

            return ['handler' => $handler, 'method' => $method];
        }

        $fqcn = ClassRef::fromClassConstant($value);
        if ($fqcn !== null) {
            return ['handler' => $fqcn, 'method' => $defaultMethod];
        }

        if ($value instanceof Node\Expr\Array_) {
            if (count($value->items) < 2) {
                return null;
            }
            $handler = ClassRef::fromClassConstant($value->items[0]->value);
            if ($handler === null) {
                return null;
            }
            $methodNode = $value->items[1]->value;
            if (! $methodNode instanceof Node\Scalar\String_) {
                return null;
            }
            $method = $methodNode->value;
            if ($method === '') {
                return null;
            }

            return ['handler' => $handler, 'method' => $method];
        }

        return null;
    }

    /** @return list<EloquentListenRecord> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
