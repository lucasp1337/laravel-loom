<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

/**
 * Chain methods that set a recorded field of a scheduled task, other than
 * its frequency and day/time constraints. The case value is the method name
 * as written in the chain.
 *
 * @internal
 */
enum ScheduleModifier: string
{
    /** `->name('nightly-report')` */
    case NAME = 'name';

    /** `->timezone('America/New_York')` */
    case TIMEZONE = 'timezone';

    /** `->withoutOverlapping()` or `->withoutOverlapping(10)` (lock expiry in minutes) */
    case WITHOUT_OVERLAPPING = 'withoutOverlapping';

    /** `->onOneServer()` */
    case ON_ONE_SERVER = 'onOneServer';

    /** `->runInBackground()` */
    case RUN_IN_BACKGROUND = 'runInBackground';

    /** `->evenInMaintenanceMode()` */
    case EVEN_IN_MAINTENANCE_MODE = 'evenInMaintenanceMode';
}
