<?php

declare(strict_types=1);

namespace App;

function makeFoo(): object
{
    return new class extends \App\Bar
    {
    };
}
