<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/** An extra documented call of a tool, shown after its main example. */
final readonly class CallVariant
{
    /** @param  array<string, mixed>  $args */
    public function __construct(public string $label, public array $args) {}
}
