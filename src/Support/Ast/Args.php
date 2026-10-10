<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * The argument list of a call, the single place that reads php-parser `Arg`
 * nodes. Positional access is by index, whatever the argument's name, which is
 * what every scanner relied on before; {@see lookup()} resolves a parameter that
 * may be passed positionally or by name.
 *
 * @internal
 */
final readonly class Args
{
    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $nodes
     */
    private function __construct(private array $nodes) {}

    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $nodes
     */
    public static function of(array $nodes): self
    {
        return new self(array_values($nodes));
    }

    /** Number of argument slots; a first-class callable `f(...)` counts as one. */
    public function count(): int
    {
        return count($this->nodes);
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** The argument at $index, or null when absent or a first-class-callable placeholder. */
    public function at(int $index): ?Arg
    {
        $node = $this->nodes[$index] ?? null;

        return $node instanceof Node\Arg ? Arg::fromNode($node, $index) : null;
    }

    /** The value expression of the argument at $index (see {@see at()}). */
    public function valueAt(int $index): ?Node\Expr
    {
        return $this->at($index)?->value;
    }

    /** The argument passed as `name: value`, or null. */
    public function named(string $name): ?Arg
    {
        foreach ($this->all() as $arg) {
            if ($arg->name === $name) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * A parameter passed either positionally at $position or by `$name:`.
     * Positional arguments precede named ones in PHP, so an unnamed argument at
     * $position wins; otherwise the named argument is used.
     */
    public function lookup(int $position, string $name): ?Arg
    {
        $positional = $this->at($position);
        if ($positional !== null && $positional->name === null) {
            return $positional;
        }

        return $this->named($name);
    }

    /**
     * Every real argument in source order (placeholders skipped), spread ones included.
     *
     * @return list<Arg>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->nodes as $index => $node) {
            if ($node instanceof Node\Arg) {
                $out[] = Arg::fromNode($node, $index);
            }
        }

        return $out;
    }

    /**
     * Value expressions of every real argument in source order.
     *
     * @return list<Node\Expr>
     */
    public function values(): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            if ($node instanceof Node\Arg) {
                $out[] = $node->value;
            }
        }

        return $out;
    }

    /** Whether any argument is spread (`...$args`). */
    public function hasUnpack(): bool
    {
        foreach ($this->nodes as $node) {
            if ($node instanceof Node\Arg && $node->unpack) {
                return true;
            }
        }

        return false;
    }

    /** Whether the call uses first-class callable syntax, `f(...)`. */
    public function isFirstClassCallable(): bool
    {
        foreach ($this->nodes as $node) {
            if (! $node instanceof Node\Arg) {
                return true;
            }
        }

        return false;
    }

    /** The list without its first $count arguments, re-indexed from zero. */
    public function skip(int $count): self
    {
        return new self(collect($this->nodes)->slice($count)->values()->all());
    }
}
