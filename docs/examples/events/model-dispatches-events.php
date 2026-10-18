<?php

// A model's $dispatchesEvents map

// app/Models/Invoice.php
namespace App\Models;

use App\Events\InvoiceCreated;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $dispatchesEvents = [
        'created' => InvoiceCreated::class, // → events[InvoiceCreated].dispatched_from.method Invoice::$dispatchesEvents[created]
        'deleted' => 'App\Events\InvoiceDeleted', // ✗ not recorded
    ];
}

// app/Events/InvoiceCreated.php
namespace App\Events;

class InvoiceCreated {}
