<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Dispatch;

use Illuminate\Support\Str;
use Lucasp\Loom\Support\Ast\CallKind;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Facades;

/**
 * Finds the {@see DispatchRule} a call matches, or null. Pure shape matching:
 * it knows nothing about scopes, closures or how a site is recorded.
 *
 * A facade that owns at least one FACADE_STATIC row claims every static call
 * made on it: an unlisted method (`Bus::dispatchIf`) yields no match instead
 * of falling through to the Dispatchable rows.
 *
 * @internal
 */
final class DispatchRuleMatcher
{
    /** @var array<string, list<DispatchRule>> rows by "shape:facade:name" */
    private array $index = [];

    /** @var array<string, Facades> facades that own at least one FACADE_STATIC row, by case name */
    private array $ownedFacades = [];

    /**
     * @param  list<DispatchRule>  $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $rule) {
            $this->index[self::bucket($rule->shape, $rule->shape === DispatchShape::FACADE_STATIC ? $rule->facade : null, $rule->name)][] = $rule;

            if ($rule->shape === DispatchShape::FACADE_STATIC && $rule->facade !== null) {
                $this->ownedFacades[$rule->facade->name] = $rule->facade;
            }
        }
    }

    /** Matcher over the table `DispatchSiteVisitor` uses. */
    public static function forDispatchSites(): self
    {
        return new self(DispatchRules::all());
    }

    public function match(CallSite $call): ?DispatchRule
    {
        $name = $call->name();
        if ($name === null) {
            return null;
        }

        return match ($call->kind()) {
            // `event(...)`: function names are case-insensitive
            CallKind::FUNCTION => $this->first(DispatchShape::GLOBAL_FUNCTION, null, Str::lower($name)),
            // `X::m(...)`: an owned facade or a Dispatchable class
            CallKind::STATIC => $this->matchStatic($call, $name),
            // `$x->m(...)`: terminal of a facade chain, or an opaque receiver
            CallKind::METHOD => $this->matchMethod($call, $name),
            CallKind::INSTANTIATION => null,
        };
    }

    private function matchStatic(CallSite $call, string $method): ?DispatchRule
    {
        $class = $call->className();
        if ($class === null) {
            return null;
        }

        foreach ($this->ownedFacades as $facade) {
            if ($facade->matches($class)) {
                // `Mail::to(...)`, `Bus::dispatchIf(...)`: owned facade, no such row, no site
                return $this->first(DispatchShape::FACADE_STATIC, $facade, $method);
            }
        }

        // `Job::dispatch()`: not an owned facade, so the class itself is the target
        return $this->first(DispatchShape::CLASS_STATIC, null, $method);
    }

    private function matchMethod(CallSite $call, string $method): ?DispatchRule
    {
        foreach ($this->index[self::bucket(DispatchShape::METHOD_CALL, null, $method)] ?? [] as $rule) {
            // Any receiver accepted (`$user->notify($n)`)
            if ($rule->facade === null || $this->isRootedAt($call, $rule)) {
                return $rule;
            }
        }

        return null;
    }

    /** True when the receiver chain bottoms out on `Facade::<rootMethod>(...)`. */
    private function isRootedAt(CallSite $call, DispatchRule $rule): bool
    {
        $root = $call->chainRoot();
        if ($root === null || $root->kind() !== CallKind::STATIC) {
            return false;
        }

        $class = $root->className();
        $method = $root->name();

        return $class !== null
            && $method !== null
            && $rule->facade?->matches($class) === true
            && collect($rule->rootMethods)->contains($method);
    }

    private function first(DispatchShape $shape, ?Facades $facade, string $name): ?DispatchRule
    {
        return ($this->index[self::bucket($shape, $facade, $name)] ?? [])[0] ?? null;
    }

    private static function bucket(DispatchShape $shape, ?Facades $facade, string $name): string
    {
        return $shape->value.':'.($facade === null ? '' : $facade->name).':'.$name;
    }
}
