<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A method as a class effectively exposes it after trait composition and
 * inheritance. `name` is the effective name (a trait alias renames it).
 *
 * @internal
 */
final readonly class ResolvedMethod
{
    /**
     * @param  string  $declaredIn  the class `self` means inside the method: the class itself when the method came from a trait
     * @param  string  $definedIn  the class or trait whose body holds the code
     * @param  list<string>  $firstParameterClasses
     */
    public function __construct(
        public string $name,
        public MethodVisibility $visibility,
        public bool $isAbstract,
        public bool $hasParameters,
        public array $firstParameterClasses,
        public string $declaredIn,
        public string $definedIn,
    ) {}

    public function isPublic(): bool
    {
        return $this->visibility === MethodVisibility::PUBLIC;
    }

    public function with(string $name, MethodVisibility $visibility): self
    {
        return new self(
            $name,
            $visibility,
            $this->isAbstract,
            $this->hasParameters,
            $this->firstParameterClasses,
            $this->declaredIn,
            $this->definedIn,
        );
    }

    public function declaredInto(string $class): self
    {
        return new self(
            $this->name,
            $this->visibility,
            $this->isAbstract,
            $this->hasParameters,
            $this->firstParameterClasses,
            $class,
            $this->definedIn,
        );
    }
}
