<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use Vendor\Package\BaseHandler;

class VendorParentListener extends BaseHandler
{
    public function handle(OrderPlaced $event): void
    {
    }
}
