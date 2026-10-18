<?php

// Handlers from a parent class or a trait

// app/Listeners/BaseOrderListener.php
namespace App\Listeners;

use App\Events\OrderPlaced;

abstract class BaseOrderListener // ✗ not recorded
{
    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/ReserveStock.php
namespace App\Listeners;

class ReserveStock extends BaseOrderListener {} // → listeners.handles.event OrderPlaced

// app/Listeners/HandlesShipping.php
namespace App\Listeners;

use App\Events\OrderShipped;

trait HandlesShipping // ✗ not recorded
{
    public function onShipped(OrderShipped $event): void {}
}

// app/Listeners/EmailTracking.php
namespace App\Listeners;

class EmailTracking // → listeners.handles.method handleShipped
{
    use HandlesShipping { onShipped as handleShipped; }
}

// app/Listeners/QuietTracking.php
namespace App\Listeners;

class QuietTracking // ✗ not recorded
{
    use HandlesShipping { onShipped as protected handleShipped; }

    public function handleNothing(): void {}
}
