<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Model\DispatchSite;

final readonly class DispatchSiteSet
{
    /** @param  list<DispatchSite>  $sites */
    public function __construct(
        public string $event,
        public array $sites,
    ) {
    }

    public function count(): int
    {
        return count($this->sites);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'count' => $this->count(),
            'dispatch_sites' => array_map(static fn (DispatchSite $s): array => [
                'file' => $s->file,
                'line' => $s->line,
                'method' => $s->method,
            ], $this->sites),
        ];
    }
}
