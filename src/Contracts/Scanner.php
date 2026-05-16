<?php

declare(strict_types=1);

namespace Lucasp\Loom\Contracts;

interface Scanner
{
    /**
     * Scan the given application root and return partial index data,
     * keyed by the schema section each subarray contributes to.
     *
     * Valid section keys: "events", "listeners", "observers",
     * "model_events", "unresolved_dispatches". A single scanner may
     * contribute to more than one section (e.g. ObserverScanner emits
     * both `observers` and `model_events`).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array;
}
