<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The kind of work a scheduled entry runs, emitted on `scheduled_tasks[*].kind`:
 * an artisan command, a queued/dispatched job, an inline closure, or a
 * shell command via `exec()`.
 *
 * @api
 */
enum ScheduleKind: string implements Summarized
{
    case COMMAND = 'command';
    case JOB = 'job';
    case CLOSURE = 'closure';
    case EXEC = 'exec';

    public function summary(): string
    {
        return match ($this) {
            self::COMMAND => '`->command()`; `target` is the signature verbatim, options included, or the command FQCN.',
            self::JOB => '`->job()`; `target` is the job FQCN.',
            self::CLOSURE => '`->call()`; `target` is `null` for an inline closure, else `Class::method`.',
            self::EXEC => '`->exec()`; `target` is the shell string.',
        };
    }
}
