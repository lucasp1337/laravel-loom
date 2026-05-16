<?php

declare(strict_types=1);

namespace App\RogueProviders;

class OtherProvider
{
    protected $listen = [
        \App\Events\OrderPlaced::class => [
            \App\Listeners\IgnoredListener::class,
        ],
    ];
}
