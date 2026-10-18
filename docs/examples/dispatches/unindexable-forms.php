<?php

// String events and closure jobs

// app/Http/Controllers/InstallController.php
namespace App\Http\Controllers;

class InstallController
{
    public function store(): void
    {
        event('service.installed'); // ✗ string_event_name
        dispatch(function () {}); // ✗ closure_job
    }
}
