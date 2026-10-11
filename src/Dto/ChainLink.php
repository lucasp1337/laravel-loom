<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\CallSite;

/**
 * One `->method(...)` link of a fluent chain such as
 * `Route::get(...)->name(...)` or `Schedule::command(...)->daily()`.
 *
 * @internal
 */
final readonly class ChainLink
{
    public function __construct(
        public string $method,
        public Args $args,
    ) {}

    /** The link a call site spells; a dynamic name reads as ''. */
    public static function fromSite(CallSite $site): self
    {
        return new self(method: (string) $site->name(), args: $site->args());
    }
}
