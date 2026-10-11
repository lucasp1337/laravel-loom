<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class ModelEventEntry
{
    /**
     * @param  list<ModelEventHandler>  $handledBy
     */
    public function __construct(
        public string $id,
        public string $model,
        public string $event,
        public array $handledBy,
    ) {}
}
