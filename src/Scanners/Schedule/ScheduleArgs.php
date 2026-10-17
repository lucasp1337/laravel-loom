<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

/**
 * Time-literal parsing shared by the scheduler frequency helpers.
 *
 * @internal
 */
final class ScheduleArgs
{
    /**
     * Split an `H:i` or bare `H` time literal into [hour, minute]; a bare hour
     * has minute 0. [null, null] when it is not in either shape.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function splitTime(string $time): array
    {
        if (! preg_match('/^(\d{1,2})(?::(\d{1,2}))?$/', $time, $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0)];
    }
}
