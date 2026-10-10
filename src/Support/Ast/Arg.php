<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * One real call argument: its position, optional name (`foo: 1`), value
 * expression, and whether it is spread (`...$x`).
 *
 * @internal
 */
final readonly class Arg
{
    public function __construct(
        public int $position,
        public ?string $name,
        public Node\Expr $value,
        public bool $unpacked,
    ) {}

    /** Whether $node is a php-parser argument node (used when walking ancestors). */
    public static function isNode(?Node $node): bool
    {
        return $node instanceof Node\Arg;
    }

    public static function fromNode(Node\Arg $node, int $position): self
    {
        return new self(
            position: $position,
            name: $node->name?->toString(),
            value: $node->value,
            unpacked: $node->unpack,
        );
    }
}
