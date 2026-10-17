<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Reads the channels a notification's `via()` returns. Resolvable bodies:
 *
 * - `return [...]` of string literals and `Class::class` constants;
 * - a local array built from such a literal plus `$x[] = 'literal'` appends,
 *   then returned (`return $n = [...]` too);
 * - several such returns, or appends and returns under a branch.
 *
 * Unconditional straight-line code gives the exact channel list. Under a
 * branch or with several returns the result is the union of every channel
 * that may apply and is flagged conditional. Anything else (a call, a keyed or
 * non-literal item, a variable the code does not build from literals) makes
 * the whole result unresolvable. Closures and nested functions are not read.
 *
 * @internal
 */
final class ViaChannels
{
    /** @var array<string, list<string>|null> local variable => channels, null once it cannot be read */
    private array $variables = [];

    /** @var list<list<string>|null> one entry per `return`, null when unreadable */
    private array $returns = [];

    private bool $conditional = false;

    private function __construct() {}

    /**
     * @return array{channels: list<string>, conditional: bool}|null null when unresolvable
     */
    public static function read(Node\Stmt\ClassMethod $via): ?array
    {
        $reader = new self;
        $reader->walk($via->stmts ?? [], false);

        if ($reader->returns === [] || collect($reader->returns)->contains(static fn (?array $list): bool => $list === null)) {
            return null;
        }

        /** @var list<list<string>> $lists */
        $lists = $reader->returns;

        return [
            'channels' => array_values(collect($lists)->flatten()->unique()->all()),
            'conditional' => $reader->conditional || count($lists) > 1,
        ];
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function walk(array $nodes, bool $branch): void
    {
        foreach ($nodes as $node) {
            match (true) {
                // `$x = [...]`, `$x[] = '...'`, or a call that may touch a tracked variable
                $node instanceof Node\Stmt\Expression => $this->expression($node->expr, $branch),
                $node instanceof Node\Stmt\Return_ => $this->returned($node->expr, $branch),
                // `if`, loops, `switch`, `try`: everything inside may or may not run
                default => $this->descend($node),
            };
        }
    }

    private function descend(Node $node): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};

            foreach (Arr::wrap($child) as $item) {
                match (true) {
                    // A nested statement list (`if` body, `else`, `case`, `catch`)
                    $item instanceof Node\Stmt => $this->walk([$item], true),
                    // A condition or subject expression may assign to a tracked variable
                    $item instanceof Node => $this->touch($item),
                    default => null,
                };
            }
        }
    }

    private function expression(Node\Expr $expr, bool $branch): void
    {
        if (! $expr instanceof Node\Expr\Assign) {
            $this->touch($expr);

            return;
        }

        $target = $expr->var;

        // `$x[] = 'literal'`
        if ($target instanceof Node\Expr\ArrayDimFetch && $target->dim === null && $target->var instanceof Node\Expr\Variable && is_string($target->var->name)) {
            $this->append($target->var->name, ValueLists::channel($expr->expr), $branch);

            return;
        }

        if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
            $this->assign($target->name, $this->literalArray($expr->expr), $branch);

            return;
        }

        $this->touch($expr);
    }

    private function returned(?Node\Expr $expr, bool $branch): void
    {
        $this->conditional = $this->conditional || $branch;

        $this->returns[] = match (true) {
            $expr instanceof Node\Expr\Array_ => ValueLists::channels($expr),
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->variables[$expr->name] ?? null,
            // `return $n = [...]`
            $expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name) => $this->assignAndRead($expr->var->name, $expr->expr, $branch),
            default => null,
        };
    }

    /** @return list<string>|null */
    private function assignAndRead(string $name, Node\Expr $value, bool $branch): ?array
    {
        $this->assign($name, $this->literalArray($value), $branch);

        return $this->variables[$name];
    }

    /** @return list<string>|null */
    private function literalArray(Node\Expr $value): ?array
    {
        return $value instanceof Node\Expr\Array_ ? ValueLists::channels($value) : null;
    }

    /**
     * @param  list<string>|null  $channels  null when the value is not a literal array
     */
    private function assign(string $name, ?array $channels, bool $branch): void
    {
        $known = Arr::exists($this->variables, $name);

        // A branch or a first assignment under one may not run: keep what was there.
        $this->variables[$name] = match (true) {
            ! $branch => $channels,
            $known && $channels !== null && $this->variables[$name] !== null => array_values(collect([...$this->variables[$name], ...$channels])->unique()->all()),
            default => null,
        };
        $this->conditional = $this->conditional || $branch;
    }

    private function append(string $name, ?string $channel, bool $branch): void
    {
        $current = $this->variables[$name] ?? null;
        $this->variables[$name] = $current === null || $channel === null
            ? null
            : array_values(collect([...$current, $channel])->unique()->all());
        $this->conditional = $this->conditional || $branch;
    }

    /** Any other use of a tracked variable makes it unreadable. */
    private function touch(Node $node): void
    {
        foreach ((new NodeFinder)->findInstanceOf($node, Node\Expr\Variable::class) as $variable) {
            if (is_string($variable->name) && Arr::exists($this->variables, $variable->name)) {
                $this->variables[$variable->name] = null;
            }
        }
    }
}
