<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/**
 * The PHP API quickstart snippets under `docs/snippets/php-api/`, in page
 * order. They share one scope, so a later snippet uses what an earlier one set.
 */
enum ApiSnippet: string implements Snippet
{
    case Load = 'load';
    case Lookup = 'lookup';
    case Walk = 'walk';
    case Errors = 'errors';

    public const DIRECTORY = 'docs/snippets/php-api';

    /** Heading above the snippet; null for the one the page opens with, under its title. */
    public function title(): ?string
    {
        return match ($this) {
            self::Load => null,
            self::Lookup => 'Look up an entry',
            self::Walk => 'Walk the graph',
            self::Errors => 'Handle errors',
        };
    }

    public function path(): string
    {
        return self::DIRECTORY."/{$this->value}.php";
    }
}
