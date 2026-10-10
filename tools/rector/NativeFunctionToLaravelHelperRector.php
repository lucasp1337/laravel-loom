<?php

declare(strict_types=1);

namespace Lucasp\Loom\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\UnionType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites native string, array and sort functions into the Illuminate `Str`,
 * `Arr` and `Collection` forms this project's NativeFunctionsTest enforces.
 *
 * Only exactly-equivalent shapes are rewritten; the argument types are read
 * from PHPStan, so an untyped argument is left alone. Mappings and the shapes
 * they skip (all calls need positional arguments, no spread):
 *
 * - `str_contains|str_starts_with|str_ends_with($h, 'lit')` -> `Str::contains|startsWith|endsWith`.
 *   Only for a non-empty string literal needle: `Str::*` returns false for `''`, native true.
 * - `implode($string, $array)` -> `Arr::join($array, $string)`. Skips the one-argument form.
 * - `array_map($fn, $array)` -> `Arr::map($array, $fn)` and `array_filter($array, $fn)` ->
 *   `Arr::where($array, $fn)`. Only for an inline closure with at most one parameter: the
 *   helpers also pass the key. Skips string callables and one-argument `array_filter`.
 * - `array_key_exists($key, $array)` -> `Arr::exists($array, $key)`. Only for a string or int
 *   key (`Arr::exists` casts floats and null).
 * - `in_array($n, $array[, bool])` -> `collect($array)->contains|containsStrict($n)`;
 *   `array_search($n, $array[, bool])` -> `collect($array)->search($n[, bool])`. Only for a
 *   scalar needle and a literal strictness flag (a callable needle is treated as a callback).
 * - `array_unique($array)` -> `collect($array)->unique(strict: true)->all()`. Only for string
 *   values (native compares as strings, the helper strictly).
 * - `array_merge`, `array_diff`, `array_diff_key`, `array_diff_assoc`, `array_intersect`,
 *   `array_intersect_key`, `array_combine`, `array_flip` -> the matching Collection method
 *   followed by `->all()`. Only when every argument is a known array; `array_diff*` and
 *   `array_intersect*` take exactly two.
 * - `array_slice($a, $o[, $l])`, `array_reverse($a)` -> `array_values(collect($a)->...->all())`.
 *   The Collection methods always preserve keys, so only for integer keys; a literal `true`
 *   preserve-keys flag maps without `array_values`, any other flag is skipped.
 * - `array_sum($array)` -> `collect($array)->sum()`. Only for int/float values.
 * - `array_reduce($array, $fn[, $init])` -> `collect($array)->reduce($fn[, $init])`. Only for
 *   an inline closure with at most two parameters (the helper also passes the key).
 * - Statement-level `sort|rsort|usort|asort|arsort|uasort|ksort|krsort|uksort($var[, $fn])` ->
 *   `$var = collect($var)->sort()...->all()` (with `->values()` for the re-indexing
 *   sort/rsort/usort). Only for a plain array variable, no flags argument, and a callable
 *   comparator; the call must not be used as a value.
 */
final class NativeFunctionToLaravelHelperRector extends AbstractRector
{
    private const STR = 'Illuminate\Support\Str';

    private const ARR = 'Illuminate\Support\Arr';

    /** Native sort function => [Collection method, re-index with values(), takes a comparator]. */
    private const SORTS = [
        'sort' => ['sort', true, false],
        'rsort' => ['sortDesc', true, false],
        'usort' => ['sort', true, true],
        'asort' => ['sort', false, false],
        'arsort' => ['sortDesc', false, false],
        'uasort' => ['sort', false, true],
        'ksort' => ['sortKeys', false, false],
        'krsort' => ['sortKeysDesc', false, false],
        'uksort' => ['sortKeysUsing', false, true],
    ];

    /** Native two-array function => Collection method. */
    private const TWO_ARRAY = [
        'array_diff' => 'diff',
        'array_diff_key' => 'diffKeys',
        'array_diff_assoc' => 'diffAssoc',
        'array_intersect' => 'intersect',
        'array_intersect_key' => 'intersectByKeys',
        'array_combine' => 'combine',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Use Illuminate Str, Arr and Collection instead of native string, array and sort functions', [
            new CodeSample(
                'in_array($name, $names, true);',
                'collect($names)->containsStrict($name);'
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class, Expression::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Expression) {
            return $this->refactorSortStatement($node);
        }

        if (! $node instanceof FuncCall || ! $node->name instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }

        $args = $this->plainArgs($node);
        if ($args === null) {
            return null;
        }

        return match ($this->getName($node)) {
            // Needle must be a non-empty literal: Str::* returns false for '', native returns true.
            'str_contains' => $this->stringHelper('contains', $args),
            'str_starts_with' => $this->stringHelper('startsWith', $args),
            'str_ends_with' => $this->stringHelper('endsWith', $args),
            'implode' => $this->implode($args),
            'array_map' => $this->mapOrFilter('map', $args, callbackFirst: true),
            'array_filter' => $this->mapOrFilter('where', $args, callbackFirst: false),
            'array_key_exists' => $this->keyExists($args),
            'in_array' => $this->membership($args, search: false),
            'array_search' => $this->membership($args, search: true),
            'array_unique' => $this->unique($args),
            'array_merge' => $this->merge($args),
            'array_slice' => $this->slice($args),
            'array_reverse' => $this->reverse($args),
            'array_flip' => $this->flip($args),
            'array_sum' => $this->sum($args),
            'array_reduce' => $this->reduce($args),
            'array_diff', 'array_diff_key', 'array_diff_assoc', 'array_intersect', 'array_intersect_key', 'array_combine' => $this->twoArrays(self::TWO_ARRAY[$this->getName($node)], $args),
            default => null,
        };
    }

    /**
     * @param  list<Expr>  $args
     */
    private function stringHelper(string $method, array $args): ?Expr
    {
        if (count($args) !== 2) {
            return null;
        }
        [$haystack, $needle] = $args;
        if (! $needle instanceof String_ || $needle->value === '') {
            return null;
        }

        return $this->staticCall(self::STR, $method, [$haystack, $needle]);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function implode(array $args): ?Expr
    {
        if (count($args) !== 2 || ! $this->isString($args[0]) || ! $this->isArray($args[1])) {
            return null;
        }

        return $this->staticCall(self::ARR, 'join', [$args[1], $args[0]]);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function mapOrFilter(string $method, array $args, bool $callbackFirst): ?Expr
    {
        if (count($args) !== 2) {
            return null;
        }
        [$callback, $array] = $callbackFirst ? $args : [$args[1], $args[0]];
        if (! $this->isInlineClosure($callback, maxParams: 1) || ! $this->isArray($array)) {
            return null;
        }

        return $this->staticCall(self::ARR, $method, [$array, $callback]);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function keyExists(array $args): ?Expr
    {
        if (count($args) !== 2 || ! $this->isStringOrInt($args[0]) || ! $this->isArray($args[1])) {
            return null;
        }

        return $this->staticCall(self::ARR, 'exists', [$args[1], $args[0]]);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function membership(array $args, bool $search): ?Expr
    {
        if (count($args) < 2 || count($args) > 3 || ! $this->isArray($args[1]) || ! $this->getType($args[0])->isScalar()->yes()) {
            return null;
        }

        // Strictness must be a literal so the right Collection method can be picked.
        $strict = false;
        if (isset($args[2])) {
            $strict = $this->boolLiteral($args[2]);
            if ($strict === null) {
                return null;
            }
        }

        if ($search) {
            return $this->chain($args[1], [['search', isset($args[2]) ? [$args[0], $args[2]] : [$args[0]]]], all: false);
        }

        return $this->chain($args[1], [[$strict ? 'containsStrict' : 'contains', [$args[0]]]], all: false);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function unique(array $args): ?Expr
    {
        if (count($args) !== 1 || ! $this->isArray($args[0]) || ! $this->getType($args[0])->getIterableValueType()->isString()->yes()) {
            return null;
        }

        $strict = new ConstFetch(new Name('true'));

        return $this->chain($args[0], [['unique', [$strict], 'strict']], all: true);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function merge(array $args): ?Expr
    {
        if (count($args) < 2 || ! $this->allArrays($args)) {
            return null;
        }

        $steps = [];
        foreach (array_slice($args, 1) as $arg) {
            $steps[] = ['merge', [$arg]];
        }

        return $this->chain($args[0], $steps, all: true);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function twoArrays(string $method, array $args): ?Expr
    {
        if (count($args) !== 2 || ! $this->allArrays($args)) {
            return null;
        }

        return $this->chain($args[0], [[$method, [$args[1]]]], all: true);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function slice(array $args): ?Expr
    {
        if (count($args) < 2 || count($args) > 4) {
            return null;
        }

        $preserve = isset($args[3]) ? $this->boolLiteral($args[3]) : false;
        if ($preserve === null || ! $this->isArray($args[0])) {
            return null;
        }

        $sliced = $this->chain($args[0], [['slice', array_slice($args, 1, 2)]], all: true);

        return $this->reindexed($args[0], $sliced, $preserve);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function reverse(array $args): ?Expr
    {
        if (count($args) < 1 || count($args) > 2) {
            return null;
        }

        $preserve = isset($args[1]) ? $this->boolLiteral($args[1]) : false;
        if ($preserve === null || ! $this->isArray($args[0])) {
            return null;
        }

        return $this->reindexed($args[0], $this->chain($args[0], [['reverse', []]], all: true), $preserve);
    }

    /**
     * Collection slice/reverse always keep keys. Native default re-indexes integer keys and
     * keeps string keys, which matches `array_values` only when every key is an integer.
     */
    private function reindexed(Expr $source, Expr $collectionResult, bool $preserveKeys): ?Expr
    {
        if ($preserveKeys) {
            return $collectionResult;
        }
        if (! $this->getType($source)->getIterableKeyType()->isInteger()->yes()) {
            return null;
        }

        return new FuncCall(new Name('array_values'), [new Arg($collectionResult)]);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function flip(array $args): ?Expr
    {
        if (count($args) !== 1 || ! $this->isArray($args[0])) {
            return null;
        }

        return $this->chain($args[0], [['flip', []]], all: true);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function sum(array $args): ?Expr
    {
        if (count($args) !== 1 || ! $this->isArray($args[0])) {
            return null;
        }

        // Native array_sum skips or coerces non-numeric values; only int/float values agree.
        $numeric = new UnionType([new IntegerType, new FloatType]);
        if (! $numeric->isSuperTypeOf($this->getType($args[0])->getIterableValueType())->yes()) {
            return null;
        }

        return $this->chain($args[0], [['sum', []]], all: false);
    }

    /**
     * @param  list<Expr>  $args
     */
    private function reduce(array $args): ?Expr
    {
        if (count($args) < 2 || count($args) > 3 || ! $this->isArray($args[0]) || ! $this->isInlineClosure($args[1], maxParams: 2)) {
            return null;
        }

        return $this->chain($args[0], [['reduce', array_slice($args, 1)]], all: false);
    }

    /** `sort($v);` as a statement becomes `$v = collect($v)->...->all();`. */
    private function refactorSortStatement(Expression $node): ?Node
    {
        $call = $node->expr;
        if (! $call instanceof FuncCall || ! $call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $function = $this->getName($call);
        if ($function === null || ! isset(self::SORTS[$function])) {
            return null;
        }

        $args = $this->plainArgs($call);
        [$method, $reindex, $comparator] = self::SORTS[$function];
        if ($args === null || count($args) !== ($comparator ? 2 : 1)) {
            return null;
        }

        $target = $args[0];
        if (! $target instanceof Variable || ! is_string($target->name) || ! $this->isArray($target)) {
            return null;
        }
        if ($comparator && ! $this->getType($args[1])->isCallable()->yes()) {
            return null;
        }

        $steps = [[$method, $comparator ? [$args[1]] : []]];
        if ($reindex) {
            $steps[] = ['values', []];
        }

        $rewritten = $this->chain(clone $target, $steps, all: true);
        $statement = new Expression(new Assign($target, $rewritten));
        $this->mirrorComments($statement, $node);

        return $statement;
    }

    /**
     * `collect($source)->step(...)->...` (+ `->all()` when requested). A step is
     * `[method, args]` or `[method, args, namedArg]` (the first arg then carries that name).
     *
     * @param  list<array{0: string, 1: list<Expr>, 2?: string}>  $steps
     */
    private function chain(Expr $source, array $steps, bool $all): Expr
    {
        $expr = new FuncCall(new Name('collect'), [new Arg($source)]);

        foreach ($steps as $step) {
            $args = [];
            foreach ($step[1] as $index => $value) {
                $named = $index === 0 && isset($step[2]) ? new Identifier($step[2]) : null;
                $args[] = new Arg($value, false, false, [], $named);
            }
            $expr = new MethodCall($expr, $step[0], $args);
        }

        return $all ? new MethodCall($expr, 'all') : $expr;
    }

    /**
     * @param  list<Expr>  $args
     */
    private function staticCall(string $class, string $method, array $args): StaticCall
    {
        return new StaticCall(
            new FullyQualified($class),
            $method,
            array_map(static fn (Expr $arg): Arg => new Arg($arg), $args),
        );
    }

    /**
     * Positional, non-spread arguments of a plain call; null for named or unpacked ones.
     *
     * @return list<Expr>|null
     */
    private function plainArgs(FuncCall $call): ?array
    {
        $exprs = [];
        foreach ($call->args as $arg) {
            if (! $arg instanceof Arg || $arg->unpack || $arg->name !== null || $arg->byRef) {
                return null;
            }
            $exprs[] = $arg->value;
        }

        return $exprs;
    }

    /**
     * @param  list<Expr>  $exprs
     */
    private function allArrays(array $exprs): bool
    {
        foreach ($exprs as $expr) {
            if (! $this->isArray($expr)) {
                return false;
            }
        }

        return true;
    }

    private function isArray(Expr $expr): bool
    {
        return $this->getType($expr)->isArray()->yes();
    }

    private function isString(Expr $expr): bool
    {
        return $this->getType($expr)->isString()->yes();
    }

    private function isStringOrInt(Expr $expr): bool
    {
        $type = $this->getType($expr);

        return $type->isString()->yes() || $type->isInteger()->yes();
    }

    /** True/false for a `true`/`false` literal, null for anything else. */
    private function boolLiteral(Expr $expr): ?bool
    {
        if (! $expr instanceof ConstFetch) {
            return null;
        }

        return match ($expr->name->toLowerString()) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /** An inline closure the helper can call with extra (key) arguments without effect. */
    private function isInlineClosure(Expr $expr, int $maxParams): bool
    {
        if (! $expr instanceof Closure && ! $expr instanceof ArrowFunction) {
            return false;
        }
        if (count($expr->params) > $maxParams) {
            return false;
        }
        foreach ($expr->params as $param) {
            if ($param->variadic || $param->byRef) {
                return false;
            }
        }

        return true;
    }
}
