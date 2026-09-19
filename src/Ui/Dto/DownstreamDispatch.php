<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

use Lucasp\Loom\Ui\NodeType;

/** @internal */
final readonly class DownstreamDispatch
{
    public function __construct(
        public NodeType $type,
        public string $target,
        public ?string $url,
        public string $via,
        public string $location,
    ) {
    }
}
