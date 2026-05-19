<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class ModelEventEntry
{
    /**
     * @param  list<string>  $handledBy
     */
    public function __construct(
        public readonly string $id,
        public readonly string $model,
        public readonly string $event,
        public readonly array $handledBy,
    ) {
    }
}
