<?php

// Routes declared with attributes

// app/Http/Controllers/PhotoController.php
namespace App\Http\Controllers;

use Spatie\RouteAttributes\Attributes\Get;

class PhotoController
{
    #[Get('/photos')] // ✗ attribute_route
    public function index(): void {}
}
