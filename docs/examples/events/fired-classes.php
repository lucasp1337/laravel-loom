<?php

// Fired classes outside app/Events

// app/Billing/RefundIssued.php
namespace App\Billing;

class RefundIssued {} // → events[RefundIssued]

// app/Http/Controllers/RefundController.php
namespace App\Http\Controllers;

use App\Billing\RefundIssued;
use Illuminate\Support\Facades\Event;

class RefundController
{
    public function store(): void
    {
        event(new RefundIssued); // → events[RefundIssued].dispatched_from.method RefundController::store
        broadcast(new RefundIssued); // → events[RefundIssued].dispatched_from.method RefundController::store
        broadcast_if(true, new RefundIssued); // → events[RefundIssued].dispatched_from.method RefundController::store
        broadcast_unless(false, new RefundIssued); // → events[RefundIssued].dispatched_from.method RefundController::store
        Event::dispatch(new RefundIssued); // → events[RefundIssued].dispatched_from.method RefundController::store
        event(new class {}); // ✗ unsupported_expression
    }
}
