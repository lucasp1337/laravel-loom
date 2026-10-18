<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

/**
 * The fixed heading structure of a page kind. Every page opens with its only
 * H1 and never skips a heading level; the rest depends on the kind.
 */
final readonly class PageOutline
{
    /**
     * @param  int  $maxDepth  deepest hand-written heading level allowed
     * @param  bool  $lead  a paragraph, not a heading, follows the H1
     * @param  bool  $generated  the page carries a generated region
     */
    public function __construct(
        public int $maxDepth,
        public bool $lead,
        public bool $generated = false,
    ) {}
}
