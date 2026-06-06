<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check;

use Lucasp\Loom\Index\Sections;

/**
 * Read-only view of a decoded index (and optional baseline) handed to each
 * rule. Section access is normalized so rules never touch raw array keys.
 */
final class CheckContext
{
    /**
     * @param  array<string,mixed>  $index
     * @param  array<string,mixed>|null  $baseline
     */
    public function __construct(
        private readonly array $index,
        private readonly ?array $baseline,
        private readonly bool $strict,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function index(): array
    {
        return $this->index;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function section(Sections $section): array
    {
        return $this->normalize($this->index, $section);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function baselineSection(Sections $section): array
    {
        if ($this->baseline === null) {
            return [];
        }

        return $this->normalize($this->baseline, $section);
    }

    public function hasBaseline(): bool
    {
        return $this->baseline !== null;
    }

    public function strict(): bool
    {
        return $this->strict;
    }

    /**
     * @param  array<string,mixed>  $source
     * @return list<array<string,mixed>>
     */
    private function normalize(array $source, Sections $section): array
    {
        $raw = $source[$section->value] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                /** @var array<string,mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
