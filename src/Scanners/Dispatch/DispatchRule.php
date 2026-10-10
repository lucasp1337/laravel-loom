<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Dispatch;

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Support\Facades;
use Lucasp\Loom\Support\Fqcn;

/**
 * One recognised dispatch form: an AST call shape plus what a match yields.
 * Rows live in {@see DispatchRules}; {@see DispatchRuleMatcher} evaluates them.
 *
 * @internal
 */
final readonly class DispatchRule
{
    /**
     * @param  Facades|null  $facade  the facade a FACADE_STATIC rule belongs to; for METHOD_CALL, the facade the receiver chain must be rooted at (null accepts any receiver)
     * @param  list<string>  $rootMethods  METHOD_CALL only: static methods on $facade that may root the chain
     * @param  int  $argIndex  position of the dispatched argument
     * @param  DispatchMode|null  $mode  fixed execution mode; null defers to the `->afterResponse()` chain (jobs only)
     * @param  int|null  $channelsAt  position of the literal channel filter argument
     * @param  string  $labelPrefix  METHOD_CALL only: prefix of the call label used when an expression cannot be rendered
     */
    public function __construct(
        public DispatchShape $shape,
        public string $name,
        public DispatchForm $form,
        public DispatchKinds $kind,
        public DispatchTarget $target,
        public ?Facades $facade = null,
        public array $rootMethods = [],
        public int $argIndex = 0,
        public ?DispatchMode $mode = null,
        public ?int $channelsAt = null,
        public string $labelPrefix = '',
    ) {}

    /** Stable identity of the row, unique within a table. */
    public function key(): string
    {
        return $this->shape->value.':'.($this->facade !== null ? Fqcn::short($this->facade->value) : '').':'.$this->name;
    }

    /** Short call description for the unresolved-dispatch fallback expression. */
    public function label(): string
    {
        return match ($this->shape) {
            // `event(...)`
            DispatchShape::GLOBAL_FUNCTION => $this->name,
            // `Bus::dispatch(...)`
            DispatchShape::FACADE_STATIC => ($this->facade !== null ? Fqcn::short($this->facade->value).'::' : '').$this->name,
            // `Job::dispatch(...)`
            DispatchShape::CLASS_STATIC => $this->name,
            // `Mail::...->send(...)`, `->notify(...)`
            DispatchShape::METHOD_CALL => $this->labelPrefix.$this->name,
        };
    }
}
