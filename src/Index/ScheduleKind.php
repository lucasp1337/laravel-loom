<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The kind of work a scheduled entry runs, emitted on `scheduled[*].kind`:
 * an artisan command, a queued/dispatched job, an inline closure, or a
 * shell command via `exec()`.
 */
enum ScheduleKind: string
{
    case COMMAND = 'command';
    case JOB = 'job';
    case CLOSURE = 'closure';
    case EXEC = 'exec';
}
