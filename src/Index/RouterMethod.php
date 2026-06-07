<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Source of truth for the `Route` facade router methods that declare a leaf
 * HTTP route in slice 1, and how each maps to an emitted HTTP verb.
 *
 * `match` and `any` are handled explicitly by the scanner (variable verb set
 * and a fixed `ANY`, respectively) and are listed here only so the visitor
 * recognises them as route-declaring roots.
 */
final class RouterMethod
{
    /** Single-verb router methods mapped to their uppercase HTTP verb. */
    public const VERB_MAP = [
        'get' => 'GET',
        'post' => 'POST',
        'put' => 'PUT',
        'patch' => 'PATCH',
        'delete' => 'DELETE',
        'options' => 'OPTIONS',
    ];

    public const ANY = 'any';

    public const MATCH = 'match';

    /**
     * Root method names the visitor treats as leaf HTTP routes.
     *
     * @return list<string>
     */
    public static function routeRoots(): array
    {
        return [...array_keys(self::VERB_MAP), self::ANY, self::MATCH];
    }
}
