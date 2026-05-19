<?php

declare(strict_types=1);

namespace Lucasp\Loom\Contracts;

interface Scanner
{
    /**
     * Return partial index data keyed by schema section name. A scanner
     * may contribute to multiple sections.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array;
}
