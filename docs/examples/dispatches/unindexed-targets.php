<?php

// Named classes with no row to link to

// app/Http/Controllers/RegisterController.php
namespace App\Http\Controllers;

use App\Support\StartLogDrain;
use Illuminate\Auth\Events\Registered;

class RegisterController
{
    public function store(object $user): void
    {
        event(new Registered($user)); // ✗ class_not_found
        StartLogDrain::dispatch(); // ✗ target_not_indexed
    }
}

// app/Support/StartLogDrain.php
namespace App\Support;

class StartLogDrain
{
    public static function dispatch(): void {}
}
