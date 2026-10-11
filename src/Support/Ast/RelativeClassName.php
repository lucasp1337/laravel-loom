<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use Illuminate\Support\Str;

/**
 * The class keywords that name a class relative to the enclosing one
 * (`self::`, `static::`, `parent::`, `new self`). NameResolver leaves them
 * untouched, so they reach a visitor as plain names.
 *
 * @internal
 */
enum RelativeClassName: string
{
    case SELF = 'self';
    case STATIC = 'static';
    case PARENT = 'parent';

    /** The keyword a written class name is, or null for an ordinary class name. */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom(Str::lower($name));
    }
}
