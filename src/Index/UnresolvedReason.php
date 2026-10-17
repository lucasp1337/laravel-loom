<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a dispatch or a listener / model-hook registration could not be resolved; the
 * values of `unresolved_dispatches[].reason`. Ordered as the schema lists them: dispatch
 * target forms, then class lookup, then registrations.
 *
 * @internal
 */
enum UnresolvedReason: string
{
    /** The target is a variable. */
    case DYNAMIC_CLASS_NAME = 'dynamic_class_name';

    /** The target comes from `app()`, `resolve()` or `->make()`. */
    case CONTAINER_RESOLUTION = 'container_resolution';

    /** The target is built by concatenation or interpolation. */
    case STRING_CONCATENATION = 'string_concatenation';

    /** The target is a ternary whose branches cannot be evaluated. */
    case CONDITIONAL_DISPATCH = 'conditional_dispatch';

    /** A `Bus::chain` / `batch` list is built at runtime. */
    case DYNAMIC_LIST = 'dynamic_list';

    /** The class is named but no scanned file declares it. Exclusive with `TARGET_NOT_INDEXED`. */
    case CLASS_NOT_FOUND = 'class_not_found';

    /** A file declares the class but it has no section row. Exclusive with `CLASS_NOT_FOUND`. */
    case TARGET_NOT_INDEXED = 'target_not_indexed';

    /** A listener registration whose event or handler is not a readable literal. */
    case UNREADABLE_LISTENER = 'unreadable_listener';

    /** A model hook whose callback is not a closure or readable callable. */
    case UNREADABLE_MODEL_HOOK = 'unreadable_model_hook';
}
