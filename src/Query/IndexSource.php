<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use Lucasp\Loom\Index\Index;

/**
 * Where {@see IndexQuery} gets its {@see Index}. Resolved per call so a
 * snapshot rewritten on disk is picked up without restarting the consumer.
 *
 * @internal
 */
interface IndexSource
{
    /** @throws IndexUnavailableException when the snapshot is missing or unreadable */
    public function index(): Index;

    public function path(): string;

    public function isAvailable(): bool;
}
