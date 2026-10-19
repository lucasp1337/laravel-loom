<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\DispatchMode;

/**
 * A dispatch whose target is a parameter of its method (`new $mailClass`,
 * `event($event)`, `$job::dispatch()`), so the class is whatever a caller
 * passes. The scanner pairs it with {@see ClassArgumentCall}s to that method.
 *
 * @internal
 */
final readonly class ParamDispatchRecord
{
    public function __construct(
        public string $classFqcn,
        public string $method,
        public int $position,
        public string $parameter,
        public DispatchForm $form,
        public DispatchKinds $kind,
        public ?DispatchMode $mode,
        public DispatchOverrides $overrides,
    ) {}

    /** Lookup key of the method, shared with the calls to it. */
    public function key(): string
    {
        return self::keyOf($this->classFqcn, $this->method);
    }

    /** `Class::method`, the method lower-cased: PHP method names are case-insensitive. */
    public static function keyOf(string $classFqcn, string $method): string
    {
        return $classFqcn.'::'.strtolower($method);
    }
}
