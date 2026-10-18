<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/**
 * The recipe snippets under `docs/snippets/recipes/`. Each loads the index
 * itself and runs alone. The value is the region key:
 * `<!-- generated:recipe:KEY -->`.
 */
enum RecipeSnippet: string implements Snippet
{
    case EventChain = 'event-chain';
    case RoutesWithoutAuth = 'routes-without-auth';
    case JobTriggers = 'job-triggers';

    public const DIRECTORY = 'docs/snippets/recipes';

    public function path(): string
    {
        return self::DIRECTORY."/{$this->value}.php";
    }
}
