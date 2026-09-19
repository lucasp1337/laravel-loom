<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Query\ChainNodeKind;

final readonly class ChainNode
{
    /** @param  list<ChainNode>  $children */
    public function __construct(
        public string $id,
        public string $label,
        public ChainNodeKind $kind,
        public ?string $file,
        public ?int $line,
        public array $children,
        public bool $isCycle,
    ) {
    }
}
