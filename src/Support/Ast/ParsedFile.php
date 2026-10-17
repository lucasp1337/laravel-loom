<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * A parsed, name-resolved file kept by {@see AstWalker}; `hash` is the source
 * content hash it was built from and `size` the source length in bytes.
 *
 * @internal
 */
final readonly class ParsedFile
{
    /**
     * @param  array<int, Node\Stmt>  $statements
     */
    public function __construct(
        public string $hash,
        public int $size,
        public array $statements,
    ) {}
}
