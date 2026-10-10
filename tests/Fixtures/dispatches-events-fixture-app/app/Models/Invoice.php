<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\InvoicePaid;
use App\Events\InvoiceCreated;
use App\Events\InvoiceDeleted;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $dispatchesEvents = [
        'created' => InvoiceCreated::class,
        'deleted' => InvoiceDeleted::class,
        'saved' => InvoicePaid::class,
        'updated' => 'not-a-class-constant',
    ];
}
