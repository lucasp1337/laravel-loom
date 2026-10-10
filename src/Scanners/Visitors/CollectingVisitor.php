<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Base for visitors that collect data across a file. Scanners reuse one visitor
 * instance for every file in a discovery loop, so per-file state has to be
 * cleared before each traversal. `beforeTraverse()` is final and calls
 * {@see self::reset()}, so a subclass cannot forget the reset.
 *
 * @internal
 */
abstract class CollectingVisitor extends NodeVisitorAbstract
{
    /**
     * @param  array<int, Node>  $nodes
     */
    final public function beforeTraverse(array $nodes): ?array
    {
        $this->reset();
        $this->resetClassScope();

        return null;
    }

    /** Clear every collected result and traversal-time stack. */
    abstract protected function reset(): void;

    /** Overridden by {@see TracksClassScope}; a no-op for visitors without class-scope state. */
    protected function resetClassScope(): void {}
}
