<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Events\InvoicePaid;

Route::get('/billing/paid', function () {
    event(new InvoicePaid);
});
