<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Dispatch;

/**
 * The call shape a {@see DispatchRule} recognises.
 *
 * @internal
 */
enum DispatchShape: string
{
    /** `name(...)`, e.g. `event($e)`. */
    case GLOBAL_FUNCTION = 'global_function';

    /** `Facade::name(...)` on a facade the rule table owns, e.g. `Bus::dispatch($j)`. */
    case FACADE_STATIC = 'facade_static';

    /** `Class::name(...)` on any class that is not an owned facade, e.g. `Job::dispatch()`. */
    case CLASS_STATIC = 'class_static';

    /** `<receiver>->name(...)`, optionally rooted at a facade call, e.g. `Mail::to($u)->send($m)`. */
    case METHOD_CALL = 'method_call';
}
