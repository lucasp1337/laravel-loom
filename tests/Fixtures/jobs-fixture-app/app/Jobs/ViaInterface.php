<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\RunsOnQueue;

class ViaInterface implements RunsOnQueue
{
    public function handle(): void {}
}
