<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Directed graph of dispatchable FQCNs derived from an index: events to the
 * targets their listeners dispatch, and jobs to the targets they dispatch.
 *
 * Cycle detection reports one representative cycle per back-edge found during a
 * deterministic DFS. This is a documented simplification: it surfaces every
 * strongly-connected region but does not enumerate every elementary cycle.
 */
final class DispatchGraph
{
    /**
     * @param  array<string,list<string>>  $adjacency
     */
    private function __construct(
        private readonly array $adjacency,
    ) {
    }

    public static function fromContext(CheckContext $context): self
    {
        $dispatchesByListener = self::dispatchTargetsByFqcn($context->section(Sections::LISTENERS));

        /** @var array<string,array<string,true>> $edges */
        $edges = [];

        foreach ($context->section(Sections::EVENTS) as $event) {
            $source = self::fqcn($event);
            if ($source === null) {
                continue;
            }
            $handledBy = $event[Field::HANDLED_BY->value] ?? null;
            if (! is_array($handledBy)) {
                continue;
            }
            foreach ($handledBy as $handler) {
                if (! is_array($handler)) {
                    continue;
                }
                $listener = $handler[Field::LISTENER->value] ?? null;
                if (! is_string($listener)) {
                    continue;
                }
                foreach ($dispatchesByListener[$listener] ?? [] as $target) {
                    $edges[$source][$target] = true;
                }
            }
        }

        foreach ($context->section(Sections::JOBS) as $job) {
            $source = self::fqcn($job);
            if ($source === null) {
                continue;
            }
            foreach (self::dispatchTargets($job) as $target) {
                $edges[$source][$target] = true;
            }
        }

        $adjacency = [];
        foreach ($edges as $source => $targets) {
            $list = array_keys($targets);
            sort($list);
            $adjacency[$source] = $list;
        }
        ksort($adjacency);

        return new self($adjacency);
    }

    /**
     * @return list<list<string>>
     */
    public function cycles(): array
    {
        $nodes = array_keys($this->adjacency);
        sort($nodes);

        /** @var array<string,int> $color 0=white, 1=gray, 2=black */
        $color = [];

        /** @var list<string> $stack */
        $stack = [];

        /** @var array<string,list<string>> $found */
        $found = [];

        foreach ($nodes as $node) {
            if (($color[$node] ?? 0) === 0) {
                $this->dfs($node, $color, $stack, $found);
            }
        }

        $cycles = array_values($found);
        usort($cycles, static fn (array $a, array $b): int => implode("\x1F", $a) <=> implode("\x1F", $b));

        return $cycles;
    }

    /**
     * @param  array<string,int>  $color
     * @param  list<string>  $stack
     * @param  array<string,list<string>>  $found
     */
    private function dfs(string $node, array &$color, array &$stack, array &$found): void
    {
        $color[$node] = 1;
        $stack[] = $node;

        foreach ($this->adjacency[$node] ?? [] as $next) {
            $state = $color[$next] ?? 0;
            if ($state === 0) {
                $this->dfs($next, $color, $stack, $found);
            } elseif ($state === 1) {
                $cycle = $this->extractCycle($stack, $next);
                if ($cycle !== null) {
                    $canonical = $this->canonicalize($cycle);
                    $found[implode("\x1F", $canonical)] = $canonical;
                }
            }
        }

        array_pop($stack);
        $color[$node] = 2;
    }

    /**
     * Slice the current stack from the back-edge target to the top, then close
     * the loop by repeating the target.
     *
     * @param  list<string>  $stack
     * @return list<string>|null
     */
    private function extractCycle(array $stack, string $target): ?array
    {
        $start = array_search($target, $stack, true);
        if ($start === false) {
            return null;
        }

        $path = array_slice($stack, $start);
        $path[] = $target;

        return $path;
    }

    /**
     * Rotate the open path so its lexicographically smallest node leads, then
     * re-close it. Yields one stable representation per cycle regardless of the
     * traversal entry point.
     *
     * @param  list<string>  $cycle  closed path, first === last
     * @return list<string>
     */
    private function canonicalize(array $cycle): array
    {
        $open = array_slice($cycle, 0, -1);
        if ($open === []) {
            return $cycle;
        }

        $pivot = 0;
        foreach ($open as $i => $node) {
            if ($node < $open[$pivot]) {
                $pivot = $i;
            }
        }

        $rotated = array_merge(array_slice($open, $pivot), array_slice($open, 0, $pivot));
        $rotated[] = $rotated[0];

        return $rotated;
    }

    /**
     * @param  list<array<string,mixed>>  $listeners
     * @return array<string,list<string>>
     */
    private static function dispatchTargetsByFqcn(array $listeners): array
    {
        $map = [];
        foreach ($listeners as $listener) {
            $fqcn = self::fqcn($listener);
            if ($fqcn === null) {
                continue;
            }
            $map[$fqcn] = self::dispatchTargets($listener);
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private static function dispatchTargets(array $entry): array
    {
        $dispatches = $entry[Field::DISPATCHES->value] ?? null;
        if (! is_array($dispatches)) {
            return [];
        }

        $targets = [];
        foreach ($dispatches as $dispatch) {
            if (! is_array($dispatch)) {
                continue;
            }
            $target = $dispatch[Field::TARGET->value] ?? null;
            if (is_string($target) && $target !== '') {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private static function fqcn(array $entry): ?string
    {
        $fqcn = $entry[Field::FQCN->value] ?? null;

        return is_string($fqcn) && $fqcn !== '' ? $fqcn : null;
    }
}
