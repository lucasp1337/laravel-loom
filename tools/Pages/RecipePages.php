<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Lucasp\Loom\Tools\Reference\RecipeSnippet;
use Lucasp\Loom\Tools\Reference\SnippetRunner;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;

/** The recipe snippets, each with what it prints against the docs fixture index. */
final class RecipePages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        $root = $this->context->root;

        return array_map(function (RecipeSnippet $snippet) use ($root): GeneratedRegion {
            $output = SnippetRunner::run($root, $this->context->fixture->indexPath(), [$snippet])[$snippet->path()];

            return new GeneratedRegion(RegionName::keyed(RegionPrefix::Recipe, $snippet->value), SnippetRunner::markdown($root, $snippet, $output));
        }, RecipeSnippet::cases());
    }
}
