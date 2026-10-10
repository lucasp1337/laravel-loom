<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Dispatch;

/**
 * Where a matched call's dispatch target comes from, and which fluent
 * modifier chains apply to the site.
 *
 * @internal
 */
enum DispatchTarget
{
    /**
     * The argument at the rule's index. A ternary with two resolvable
     * branches emits both. Modifiers: the argument's own chain and the outer
     * PendingDispatch chain wrapping the call.
     */
    case PENDING_ARGUMENT;

    /**
     * The argument at the rule's index; no ternary support. Modifiers: the
     * argument's own chain and the receiver chain of the call
     * (`Mail::to()->locale()->send()`).
     */
    case ARGUMENT;

    /**
     * Each item of the array literal at argument 0. Modifiers per item as for
     * {@see self::PENDING_ARGUMENT}.
     */
    case LIST_ITEMS;

    /** The class the static call is made on (`Job::dispatch()`). */
    case STATIC_CLASS;
}
