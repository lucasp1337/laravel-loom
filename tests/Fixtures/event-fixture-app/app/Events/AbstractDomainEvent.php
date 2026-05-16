<?php

declare(strict_types=1);

namespace App\Events;

abstract class AbstractDomainEvent
{
    abstract public function name(): string;
}
