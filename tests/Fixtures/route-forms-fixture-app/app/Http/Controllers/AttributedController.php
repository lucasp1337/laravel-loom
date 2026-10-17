<?php

namespace App\Http\Controllers;

use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('servers')]
final class AttributedController
{
    #[Get('{server}', name: 'servers.show')]
    public function show(): void {}
}
