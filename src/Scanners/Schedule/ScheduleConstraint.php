<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

/**
 * Chain methods that restrict when a task runs and are recorded as a
 * constraint string. The case value is the method name as written in the
 * chain. Day-of-week helpers (`->mondays()`) are recorded by name and are not
 * listed here.
 *
 * @internal
 */
enum ScheduleConstraint: string
{
    /** `->between('8:00', '17:00')` */
    case BETWEEN = 'between';

    /** `->unlessBetween('23:00', '4:00')` */
    case UNLESS_BETWEEN = 'unlessBetween';

    /** `->when(fn () => ...)` */
    case WHEN = 'when';

    /** `->skip(fn () => ...)` */
    case SKIP = 'skip';

    /** `->environments(['staging', 'production'])` or variadic strings */
    case ENVIRONMENTS = 'environments';

    /** `->days(0, 3)` or `->days([0, 3])` */
    case DAYS = 'days';
}
