<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Inherits ShouldQueue from AbstractJob (which implements it directly).
 * Used to verify JobsScanner detects indirect ShouldQueue via the resolver.
 */
class IndirectlyQueued extends AbstractJob
{
    public string $queue = 'reports';
}
