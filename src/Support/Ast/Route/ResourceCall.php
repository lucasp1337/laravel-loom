<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast\Route;

use Lucasp\Loom\Dto\ResourceTarget;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use PhpParser\Node;

/**
 * Reads a resource-family route call (`resource`, `apiResource`, `singleton`,
 * `apiSingleton` and their array forms) into the resources it registers and
 * the options that apply.
 *
 * @internal
 */
final class ResourceCall
{
    /** @var list<ResourceTarget> */
    public array $targets = [];

    public ResourceOptions $options;

    /** True when a resource name, or the array of resources, was not literal. */
    public bool $dynamicTargets = false;

    public function __construct(RouteChainEntry $entry)
    {
        $kind = $entry->root->resourceKind();
        $args = $entry->rootArgs;
        $this->options = new ResourceOptions;

        // resources([...]): one target per `'name' => Controller::class` item
        if ($entry->root->isBatch()) {
            $this->readBatch($args->valueAt(0));
            $this->options->readArray($args->valueAt(1));
        } else {
            $name = Literal::string($args->valueAt(0));
            if ($name === null || trim($name, '/') === '') {
                $this->dynamicTargets = true;
            } else {
                $this->targets[] = new ResourceTarget(trim($name, '/'), ClassRef::fromClassConstant($args->valueAt(1)));
            }
            $this->options->readArray($args->valueAt(2));
        }

        // attributes written before the call override the options array key by key
        $leading = $entry->leading;
        if ($leading->middleware !== null) {
            $this->options->middleware = $leading->middleware;
        }
        if ($leading->excluded !== []) {
            $this->options->excluded = $leading->excluded;
        }
        if ($leading->name !== '') {
            $this->options->as = $leading->name;
        }

        // apiResource() / apiSingleton() seed `only` unless the options set it
        $apiOnly = $kind?->apiOnly();
        if ($apiOnly !== null && $this->options->only === null) {
            $this->options->only = array_values(collect($apiOnly)
                ->map(static fn ($action): string => $action->value)
                ->reject(fn (string $action): bool => collect($this->options->except)->containsStrict($action))
                ->all());
        }

        // modifiers after the call; the single-resource forms only
        if (! $entry->root->isBatch()) {
            foreach (collect($entry->chain)->slice(1)->all() as $link) {
                $this->options->applyModifier($link->method, $link->args);
            }
        }
    }

    private function readBatch(?Node\Expr $node): void
    {
        if (! $node instanceof Node\Expr\Array_) {
            $this->dynamicTargets = true;

            return;
        }

        foreach ($node->items as $item) {
            $name = $item->key === null ? null : Literal::string($item->key);
            if ($name === null || $item->unpack || trim($name, '/') === '') {
                $this->dynamicTargets = true;

                continue;
            }
            $this->targets[] = new ResourceTarget(trim($name, '/'), ClassRef::fromClassConstant($item->value));
        }
    }
}
