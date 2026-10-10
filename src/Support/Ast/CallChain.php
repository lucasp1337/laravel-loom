<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * A fluent call chain such as `Route::get(...)->name(...)->middleware(...)`,
 * links ordered root-first. The root is the leftmost call: a static call, or a
 * method call on any non-call receiver (a variable, `$this`, a function call).
 *
 * @internal
 */
final readonly class CallChain
{
    /**
     * @param  non-empty-list<CallSite>  $links
     */
    private function __construct(private array $links) {}

    /**
     * Collect the chain ending at $outer, or null when it cannot be read: a
     * dynamic method name, a dynamic static class, or no call at all.
     */
    public static function from(Node\Expr $outer): ?self
    {
        $links = [];
        $current = $outer;

        while (true) {
            // `$receiver->method(...)`: a link, then keep descending the receiver
            if ($current instanceof Node\Expr\MethodCall) {
                if (! $current->name instanceof Node\Identifier) {
                    return null;
                }
                $site = CallSite::of($current);
                if ($site === null) {
                    return null;
                }
                array_unshift($links, $site);
                $current = $current->var;

                continue;
            }

            // `Class::method(...)`: always the chain root
            if ($current instanceof Node\Expr\StaticCall) {
                if (! $current->name instanceof Node\Identifier || ! $current->class instanceof Node\Name) {
                    return null;
                }
                $site = CallSite::of($current);
                if ($site === null) {
                    return null;
                }
                array_unshift($links, $site);
            }

            // a static call ends the chain; so does any non-call receiver (a variable, `new`, ...)
            break;
        }

        return $links === [] ? null : new self($links);
    }

    /**
     * All links, root first.
     *
     * @return non-empty-list<CallSite>
     */
    public function links(): array
    {
        return $this->links;
    }

    public function root(): CallSite
    {
        return $this->links[0];
    }

    /**
     * Every link after the root, in source order.
     *
     * @return list<CallSite>
     */
    public function modifiers(): array
    {
        return array_values(collect($this->links)->slice(1)->all());
    }

    /**
     * The chain minus its last link; empty when only the root remains.
     *
     * @return list<CallSite>
     */
    public function withoutLast(): array
    {
        return array_values(collect($this->links)->slice(0, -1)->all());
    }
}
