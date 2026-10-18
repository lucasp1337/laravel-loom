<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

use Illuminate\Support\Str;

/** What a consumer page is for, decided by its directory. */
enum PageKind: string
{
    case Landing = 'landing';
    case Concept = 'concept';
    case Guide = 'guide';
    case Recipe = 'recipe';
    case Reference = 'reference';
    case Primitive = 'primitive';

    /** Null for a page outside the docs tree, such as the README. */
    public static function of(string $path): ?self
    {
        return match (true) {
            // Detection pages render the example corpus of one primitive.
            str_starts_with($path, 'docs/reference/detection/') => self::Primitive,
            str_starts_with($path, 'docs/reference/') => self::Reference,
            str_starts_with($path, 'docs/guides/') => self::Guide,
            str_starts_with($path, 'docs/recipes/') => self::Recipe,
            str_starts_with($path, 'docs/concepts/') => self::Concept,
            // Top-level pages: the home page and getting started.
            str_starts_with($path, 'docs/') && ! Str::contains(Str::after($path, 'docs/'), '/') => self::Landing,
            default => null,
        };
    }

    public function outline(): PageOutline
    {
        return match ($this) {
            // The home page is a list of links under its title.
            self::Landing => new PageOutline(maxDepth: 3, lead: false),
            self::Concept => new PageOutline(maxDepth: 3, lead: true),
            // A task page: who it is for and what you get, then the steps.
            self::Guide => new PageOutline(maxDepth: 3, lead: true),
            // One screen: no subsections.
            self::Recipe => new PageOutline(maxDepth: 2, lead: true),
            self::Reference => new PageOutline(maxDepth: 4, lead: true),
            // The title, then the generated examples; nothing hand-written in between.
            self::Primitive => new PageOutline(maxDepth: 1, lead: false, generated: true),
        };
    }
}
