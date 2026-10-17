<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a dispatch could not be resolved to a class (or that class located); the values of
 * `unresolved_dispatches[].reason`.
 *
 * @internal
 */
enum UnresolvedReason: string
{
    case DYNAMIC_CLASS_NAME = 'dynamic_class_name';
    case CONTAINER_RESOLUTION = 'container_resolution';
    case STRING_CONCATENATION = 'string_concatenation';
    case CONDITIONAL_DISPATCH = 'conditional_dispatch';
    case CLASS_NOT_FOUND = 'class_not_found';
}
