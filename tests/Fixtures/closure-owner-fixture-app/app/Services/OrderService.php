<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Committed;
use App\Events\ItemAdded;
use App\Events\Nested;
use App\Events\Notified;
use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Events\Tapped;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function arrow(): void
    {
        DB::transaction(fn () => event(new OrderPlaced));
    }

    public function closure(): void
    {
        DB::transaction(function () {
            event(new OrderShipped);
        });
    }

    public function each(array $items): void
    {
        collect($items)->each(function ($item) {
            event(new ItemAdded);
        });
    }

    public function tapped(): void
    {
        tap($this, static function () {
            event(new Tapped);
        });
    }

    public function variable(): void
    {
        $notify = function () {
            event(new Notified);
        };

        $notify();
    }

    public function afterCommit(): void
    {
        DB::afterCommit(fn () => event(new Committed));
    }

    public function nested(): void
    {
        DB::transaction(function () {
            collect([1])->each(fn () => event(new Nested));
        });
    }

    public function unresolved(string $kind): void
    {
        DB::transaction(function () use ($kind) {
            event(new $kind);
        });
    }
}
