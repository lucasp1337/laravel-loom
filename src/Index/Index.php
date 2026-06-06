<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Immutable Loom index. Serializes to schema/loom-index.schema.json.
 */
final class Index
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections  keyed by section name (Sections::value)
     */
    public function __construct(
        public readonly string $loomVersion,
        public readonly string $scannedAt,
        public readonly string $laravelVersion,
        public readonly array $sections = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stats = [];
        foreach (SectionRegistry::statsNames() as $name) {
            $stats[$name] = count($this->sections[$name] ?? []);
        }

        $payload = [
            'loom_version' => $this->loomVersion,
            'scanned_at' => $this->scannedAt,
            'laravel_version' => $this->laravelVersion,
            'stats' => $stats,
        ];

        foreach (SectionRegistry::names() as $name) {
            $payload[$name] = $this->sections[$name] ?? [];
        }

        return $payload;
    }
}
