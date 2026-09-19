<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Query\Dto\ChainEdge;
use Lucasp\Loom\Query\Dto\EventChain;
use Lucasp\Loom\Query\HandlerKind;
use Lucasp\Loom\Ui\NodeType;

/**
 * Projects an {@see EventChain} into the node/edge list the chain page draws.
 *
 * Depth-first from the root event. Every occurrence gets a path key, so the
 * same class can appear on several branches; an event already drawn earlier
 * becomes a childless cycle node instead of a loop edge.
 *
 * @internal
 */
final class ChainGraph
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array{s: string, t: string}> */
    private array $edges = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, true> */
    private array $usedKeys = [];

    /** @var array<string, list<ChainEdge>> */
    private array $edgesByEvent = [];

    /**
     * @param  list<string>  $collapsed  path keys whose children are hidden
     */
    private function __construct(
        EventChain $chain,
        private readonly array $collapsed,
        private readonly ?string $selected,
    ) {
        foreach ($chain->edges as $edge) {
            $this->edgesByEvent[$edge->event][] = $edge;
        }
    }

    /**
     * @param  list<string>  $collapsed
     * @return array{root: string, depth: int, truncated: bool, nodes: list<array<string, mixed>>, edges: list<array{s: string, t: string}>}
     */
    public static function build(EventChain $chain, array $collapsed = [], ?string $selected = null): array
    {
        $graph = new self($chain, $collapsed, $selected);
        $graph->event($chain->root, null, 0);

        return [
            'root' => $chain->root,
            'depth' => $chain->depth,
            'truncated' => $chain->truncated,
            'nodes' => $graph->nodes,
            'edges' => $graph->edges,
        ];
    }

    private function event(string $event, ?string $parentKey, int $depth): void
    {
        $key = $this->key($parentKey, $event);

        if (isset($this->seen[$event])) {
            $this->push($key, $parentKey, $event, NodeType::CYCLE, $depth, "\u{21BA} ".Fqcn::short($event)."\n(already shown)", null, false, false);

            return;
        }
        $this->seen[$event] = true;

        $handlers = $this->edgesByEvent[$event] ?? [];
        $collapsed = in_array($key, $this->collapsed, true);
        $this->push($key, $parentKey, $event, NodeType::EVENT, $depth, $this->label(NodeType::EVENT, $event), null, $handlers !== [], $collapsed);

        if ($collapsed) {
            return;
        }

        foreach ($handlers as $handler) {
            $this->handler($handler, $key, $depth + 1);
        }
    }

    private function handler(ChainEdge $edge, string $parentKey, int $depth): void
    {
        $closure = $edge->handlerKind === HandlerKind::CLOSURE;
        [$id, $method] = $closure ? [$edge->handler, null] : $this->splitHandler($edge->handler);
        $type = $closure ? NodeType::CLOSURE : NodeType::LISTENER;
        $key = $this->key($parentKey, $edge->handler);
        $collapsed = in_array($key, $this->collapsed, true);
        $dispatches = $edge->dispatches;

        $this->push(
            $key,
            $parentKey,
            $id,
            $type,
            $depth,
            $this->label($type, $closure ? $this->closureName($id) : $id),
            $method,
            $dispatches !== [],
            $collapsed,
        );

        if ($collapsed) {
            return;
        }

        $drawn = [];
        foreach ($dispatches as $dispatch) {
            $identity = $dispatch->kind->value.'|'.$dispatch->target;
            if (isset($drawn[$identity])) {
                continue;
            }
            $drawn[$identity] = true;

            if ($dispatch->kind === DispatchKinds::EVENT) {
                $this->event($dispatch->target, $key, $depth + 1);

                continue;
            }
            $target = NodeType::forDispatch($dispatch->kind);
            $this->push(
                $this->key($key, $dispatch->target),
                $key,
                $dispatch->target,
                $target,
                $depth + 1,
                $this->label($target, $dispatch->target),
                null,
                false,
                false,
            );
        }
    }

    private function push(
        string $key,
        ?string $parentKey,
        string $id,
        NodeType $type,
        int $depth,
        string $label,
        ?string $sub,
        bool $hasChildren,
        bool $collapsed,
    ): void {
        $this->nodes[] = [
            'key' => $key,
            'id' => $id,
            'type' => $type->value,
            'depth' => $depth,
            'label' => $label,
            'sub' => $sub,
            'hasChildren' => $hasChildren,
            'collapsed' => $collapsed,
            'selected' => $key === $this->selected,
        ];

        if ($parentKey !== null) {
            $this->edges[] = ['s' => $parentKey, 't' => $key];
        }
    }

    private function key(?string $parentKey, string $id): string
    {
        $key = $parentKey === null ? $id : $parentKey.'>'.$id;
        for ($n = 2; isset($this->usedKeys[$key]); $n++) {
            $key = ($parentKey === null ? $id : $parentKey.'>'.$id).'#'.$n;
        }
        $this->usedKeys[$key] = true;

        return $key;
    }

    private function label(NodeType $type, string $id): string
    {
        return $type->glyph().' · '.$type->label()."\n".Fqcn::short($id);
    }

    /** `dir/File.php:12` reads better as `File.php:12`. */
    private function closureName(string $location): string
    {
        $pos = strrpos($location, '/');

        return $pos === false ? $location : substr($location, $pos + 1);
    }

    /** @return array{0: string, 1: ?string} */
    private function splitHandler(string $ref): array
    {
        $pos = strrpos($ref, '::');

        return $pos === false ? [$ref, null] : [substr($ref, 0, $pos), substr($ref, $pos + 2)];
    }
}
