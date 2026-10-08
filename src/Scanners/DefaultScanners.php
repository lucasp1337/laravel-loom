<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScanScope;

/**
 * The canonical scanner set `loom:scan` runs. Single source of truth so the
 * CLI, the benchmark suite, and any consumer build the same index.
 *
 * @internal
 */
final class DefaultScanners
{
    /**
     * Every scanner shares one walker, so parse failures are collected in a
     * single place ({@see AstWalker::skippedFiles()}).
     *
     * @return list<Scanner>
     */
    public static function all(?ScanScope $scope = null, ?AstWalker $walker = null): array
    {
        $walker ??= new AstWalker;

        return [
            new EventScanner(walker: $walker, scope: $scope),
            new ListenerScanner(walker: $walker, scope: $scope),
            new ObserverScanner(walker: $walker, scope: $scope),
            new JobsScanner(walker: $walker, scope: $scope),
            new MailableScanner(walker: $walker, scope: $scope),
            new NotificationScanner(walker: $walker, scope: $scope),
            new DispatchScanner(walker: $walker, scope: $scope),
            new ScheduleScanner(walker: $walker, scope: $scope),
            new RouteScanner(walker: $walker, scope: $scope),
        ];
    }

    public static function registerOn(IndexBuilder $builder, ?ScanScope $scope = null, ?AstWalker $walker = null): void
    {
        foreach (self::all($scope, $walker) as $scanner) {
            $builder->register($scanner);
        }
    }
}
