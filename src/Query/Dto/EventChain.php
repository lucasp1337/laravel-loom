<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Query\ChainNodeKind;

/**
 * Transitive handler/dispatch chain rooted at an event, bounded by depth.
 */
final readonly class EventChain
{
    /**
     * @param  list<ChainEdge>  $edges
     * @param  list<string>  $eventsReached
     * @param  list<ChainCycle>  $cycles
     * @param  bool  $truncated  events remain unexpanded because the depth bound was hit
     */
    public function __construct(
        public string $root,
        public int $depth,
        public array $edges,
        public array $eventsReached,
        public array $cycles = [],
        public bool $truncated = false,
    ) {
    }

    /**
     * @param  bool  $extended  also emit `cycles` and `truncated` (omitted from the legacy MCP shape)
     * @return array<string, mixed>
     */
    public function toArray(bool $extended = false): array
    {
        $out = [
            'root' => $this->root,
            'depth' => $this->depth,
            'edges' => array_map(static fn (ChainEdge $e): array => $e->toArray(), $this->edges),
            'events_reached' => $this->eventsReached,
        ];

        if ($extended) {
            $out['cycles'] = array_map(static fn (ChainCycle $c): array => $c->toArray(), $this->cycles);
            $out['truncated'] = $this->truncated;
        }

        return $out;
    }

    /**
     * Nested projection: event -> handlers -> dispatched events/targets. An event
     * reached a second time is a childless node flagged `isCycle`.
     */
    public function tree(): ChainNode
    {
        $counter = 0;
        $expanded = [];

        return $this->eventNode($this->root, $counter, $expanded, null, null);
    }

    /**
     * Cytoscape.js element list for the same projection.
     *
     * @return array{nodes: list<array{data: array<string, mixed>}>, edges: list<array{data: array<string, string>}>}
     */
    public function cytoscapeElements(): array
    {
        $nodes = [];
        $edges = [];
        $walk = function (ChainNode $node, ?ChainNode $parent) use (&$walk, &$nodes, &$edges): void {
            $nodes[] = ['data' => [
                'id' => $node->id,
                'label' => $node->label,
                'kind' => $node->kind->value,
                'file' => $node->file,
                'line' => $node->line,
                'isCycle' => $node->isCycle,
            ]];
            if ($parent !== null) {
                $edges[] = ['data' => ['id' => $parent->id.'>'.$node->id, 'source' => $parent->id, 'target' => $node->id]];
            }
            foreach ($node->children as $child) {
                $walk($child, $node);
            }
        };
        $walk($this->tree(), null);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** @param  array<string, true>  $expanded */
    private function eventNode(string $event, int &$counter, array &$expanded, ?string $file, ?int $line): ChainNode
    {
        $id = 'n'.(++$counter);

        if (isset($expanded[$event])) {
            return new ChainNode($id, $event, ChainNodeKind::EVENT, $file, $line, [], true);
        }
        $expanded[$event] = true;

        $children = [];
        foreach ($this->edges as $edge) {
            if ($edge->event === $event) {
                $children[] = $this->handlerNode($edge, $counter, $expanded);
            }
        }

        return new ChainNode($id, $event, ChainNodeKind::EVENT, $file, $line, $children, false);
    }

    /** @param  array<string, true>  $expanded */
    private function handlerNode(ChainEdge $edge, int &$counter, array &$expanded): ChainNode
    {
        $id = 'n'.(++$counter);
        $children = [];

        foreach ($edge->dispatches as $dispatch) {
            if ($dispatch->kind === DispatchKinds::EVENT) {
                $children[] = $this->eventNode($dispatch->target, $counter, $expanded, $dispatch->file, $dispatch->line);
            } else {
                $children[] = new ChainNode('n'.(++$counter), $dispatch->target, ChainNodeKind::DISPATCH_TARGET, $dispatch->file, $dispatch->line, [], false);
            }
        }

        return new ChainNode($id, $edge->handler, ChainNodeKind::HANDLER, $edge->file, $edge->line, $children, false);
    }
}
