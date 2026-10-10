<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

/**
 * The call shapes a {@see CallSite} can wrap.
 *
 * @internal
 */
enum CallKind
{
    /** `$receiver->method(...)` */
    case METHOD;

    /** `Class::method(...)` */
    case STATIC;

    /** `function(...)` */
    case FUNCTION;

    /** `new Class(...)` */
    case INSTANTIATION;
}
