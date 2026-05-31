<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Where a scheduler chain was found, steering how `ScheduleChainVisitor`
 * resolves its receiver. Internal to schedule discovery — not emitted in the
 * public schema.
 *
 * `KERNEL` and `BOOTSTRAP` accept a variable `$schedule` receiver (the
 * `schedule(Schedule $schedule)` convention); `FACADE` matches only the
 * `Schedule` facade and ignores variable receivers.
 */
enum ScheduleMode: string
{
    case KERNEL = 'kernel';
    case BOOTSTRAP = 'bootstrap';
    case FACADE = 'facade';
}
