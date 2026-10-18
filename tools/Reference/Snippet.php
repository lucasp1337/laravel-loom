<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/** A PHP file under `docs/snippets/` that {@see SnippetRunner} runs against the docs fixture index. */
interface Snippet
{
    /** Path relative to the repository root. */
    public function path(): string;
}
