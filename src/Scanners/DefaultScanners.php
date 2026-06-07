<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Index\IndexBuilder;

/**
 * The canonical scanner set `loom:scan` runs. Single source of truth so the
 * CLI, the benchmark suite, and any consumer build the same index.
 */
final class DefaultScanners
{
    /**
     * @return list<Scanner>
     */
    public static function all(): array
    {
        return [
            new EventScanner,
            new ListenerScanner,
            new ObserverScanner,
            new JobsScanner,
            new MailableScanner,
            new NotificationScanner,
            new DispatchScanner,
            new ScheduleScanner,
            new RouteScanner,
        ];
    }

    public static function registerOn(IndexBuilder $builder): void
    {
        foreach (self::all() as $scanner) {
            $builder->register($scanner);
        }
    }
}
