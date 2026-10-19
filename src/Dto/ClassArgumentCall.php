<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A method call on a receiver of known class that passes at least one class
 * (`app(MailService::class)->send(WelcomeMail::class)`). Paired with a
 * {@see ParamDispatchRecord} on the called method, it becomes a dispatch site
 * at this call.
 *
 * @internal
 */
final class ClassArgumentCall
{
    /**
     * @param  list<ClassArgument>  $arguments
     */
    public function __construct(
        public readonly ?string $callerClass,
        public readonly ?string $callerMethod,
        public readonly string $receiverClass,
        public readonly string $method,
        public readonly array $arguments,
        public readonly int $line,
        public readonly bool $inClosure,
        public readonly string $expression,
        public ?string $file = null,
    ) {}

    /** The class passed for a parameter: positionally at $position, else by `$name:`. */
    public function argumentFor(int $position, string $name): ?string
    {
        $arguments = collect($this->arguments);
        $positional = $arguments->first(fn (ClassArgument $arg): bool => $arg->name === null && $arg->position === $position);

        return ($positional ?? $arguments->first(fn (ClassArgument $arg): bool => $arg->name === $name))?->target;
    }
}
