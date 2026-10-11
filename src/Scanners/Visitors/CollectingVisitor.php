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
 * Read names on `leaveNode()`: NameResolver rewrites child names as it
 * descends, so an outer node's inner `new X` or `X::class` is only resolved on
 * leave. The exception is `namespacedName` on a class declaration, set on
 * enter. Expose the collected records through a getter that the scanner reads
 * after each walk, and read call arguments through `Support\Ast`.
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
