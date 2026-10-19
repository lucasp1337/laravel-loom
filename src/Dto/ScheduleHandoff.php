<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Illuminate\Support\Str;

/**
 * A call that hands the Schedule to a method of another class, captured by
 * ScheduleChainVisitor so the scanner can read that method too. `class` and
 * `method` are null when the callee cannot be read statically.
 *
 * @internal
 */
final readonly class ScheduleHandoff
{
    /**
     * @param  int  $argument  position of the Schedule among the call's arguments
     * @param  list<string>  $guards  enclosing `if` branches at the call, rendered as constraint strings
     */
    public function __construct(
        public ?string $class,
        public ?string $method,
        public int $argument,
        public int $line,
        public array $guards = [],
    ) {}

    public function isFollowable(): bool
    {
        return $this->class !== null && $this->method !== null;
    }

    /** `Class::method`, lower-cased: one walk per callee. */
    public function key(): string
    {
        return Str::lower($this->class.'::'.$this->method);
    }

    /** @param  list<string>  $outer  guards around the call that reached this one */
    public function withOuterGuards(array $outer): self
    {
        return new self($this->class, $this->method, $this->argument, $this->line, [...$outer, ...$this->guards]);
    }
}
