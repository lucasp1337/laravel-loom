<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Concerns\Queueish;

class UsesTrait
{
    use Queueish;

    public function handle(): void {}
}
