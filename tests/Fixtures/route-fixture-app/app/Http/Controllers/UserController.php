<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class UserController
{
    public function index(): void
    {
    }

    public function show(): void
    {
    }

    public function store(): void
    {
    }

    public function update(): void
    {
    }

    public function multi(): void
    {
    }

    public function any(): void
    {
    }

    public function checkout(): void
    {
        event(new \App\Events\OrderPlaced);
        \App\Jobs\ProcessOrder::dispatch();
    }
}
