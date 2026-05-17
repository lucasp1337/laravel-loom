<?php

declare(strict_types=1);

namespace App\Jobs;

class RunReport
{
    public string $queue = 'reports';

    public function handle(): void
    {
    }
}
