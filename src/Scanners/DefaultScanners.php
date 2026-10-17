<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\RouteFileDiscovery;
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
     * single place ({@see AstWalker::skippedFiles()}). The route and dispatch
     * scanners share one {@see RouteFileDiscovery}, so route files are
     * discovered once.
     *
     * @return list<Scanner>
     */
    public static function all(?ScanScope $scope = null, ?AstWalker $walker = null, ?RouteFileDiscovery $discovery = null): array
    {
        $walker ??= new AstWalker;
        $discovery ??= new RouteFileDiscovery($scope ?? ScanScope::default(), $walker);
        $locator = new Psr4ClassLocator(($scope ?? ScanScope::default())->composerFiles());

        return [
            new EventScanner(walker: $walker, locator: $locator, scope: $scope),
            new ListenerScanner(walker: $walker, locator: $locator, scope: $scope),
            new ObserverScanner(walker: $walker, locator: $locator, scope: $scope),
            new JobsScanner(walker: $walker, locator: $locator, scope: $scope),
            new MailableScanner(walker: $walker, locator: $locator, scope: $scope),
            new NotificationScanner(walker: $walker, locator: $locator, scope: $scope),
            new DispatchScanner(walker: $walker, scope: $scope, routeDiscovery: $discovery),
            new ScheduleScanner(walker: $walker, scope: $scope),
            new RouteScanner(walker: $walker, scope: $scope, routeDiscovery: $discovery),
        ];
    }

    public static function registerOn(IndexBuilder $builder, ?ScanScope $scope = null, ?AstWalker $walker = null, ?RouteFileDiscovery $discovery = null): void
    {
        foreach (self::all($scope, $walker, $discovery) as $scanner) {
            $builder->register($scanner);
        }
    }
}
