<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

/**
 * The branch of an enclosing `if` a scheduled task sits in, recorded as a
 * constraint because the condition is not evaluated.
 *
 * @internal
 */
enum ScheduleGuard: string
{
    /** In the `then` block: `if (config('x')) { ... }` */
    case THEN = 'if';

    /** In an `elseif` block: `elseif (config('y'))` */
    case OTHERWISE_IF = 'elseif';

    /** In the `else` block; the condition shown is the `if` condition that was false. */
    case OTHERWISE = 'else';

    /** The constraint string for a condition's source text. */
    public function render(string $condition): string
    {
        return $this->value.'('.$condition.')';
    }
}
