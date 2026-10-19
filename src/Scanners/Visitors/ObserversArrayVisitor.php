<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ObserverPair;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Collects (model, observers) pairs from `$observers` on EventServiceProvider
 * classes, which the provider passes to `$model::observe()`. Keys and values
 * are `X::class` constants or class-name strings; a value may be one observer
 * or an array of them. Items of any other shape are skipped, as for
 * `observe()` calls.
 *
 * @internal
 */
final class ObserversArrayVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;

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
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        $this->leaveClassScope($node);

        return null;
    }

    private function handleProperty(Node\Stmt\Property $node): void
    {
        if (! $this->inEventServiceProvider()) {
            return;
        }

        foreach ($node->props as $prop) {
            if ($prop->name->toString() !== 'observers' || ! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($prop->default->items as $item) {
                $model = $item->key === null ? null : ListenerRef::event($item->key);
                $observers = $this->observersOf($item->value);
                if ($model === null || $observers === []) {
                    continue;
                }

                $this->pairs[] = new ObserverPair(model: $model, observers: $observers, line: $item->getStartLine());
            }
        }
    }

    /**
     * One observer or an array of them, as class names.
     *
     * @return list<string>
     */
    private function observersOf(Node\Expr $value): array
    {
        $values = $value instanceof Node\Expr\Array_
            ? array_map(static fn (Node\ArrayItem $item): Node\Expr => $item->value, $value->items)
            : [$value];

        return array_values(collect($values)
            ->map(static fn (Node\Expr $observer): ?string => ListenerRef::fold($observer))
            ->filter(static fn (?string $observer): bool => $observer !== null && $observer !== '')
            ->map(static fn (string $observer): string => Fqcn::normalize($observer))
            ->all());
    }

    /** @return list<ObserverPair> */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
