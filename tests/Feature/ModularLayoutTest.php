<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Support\ComposerPsr4Map;
use Lucasp\Loom\Support\ScanCoverage;
use Lucasp\Loom\Support\ScanScope;

function modularRoot(): string
{
    return dirname(__DIR__).'/Fixtures/modular-fixture-app';
}

it('reads PSR-4 maps from composer files the root merge-plugin includes', function () {
    $file = ComposerPsr4Map::fromAppRoot(modularRoot())->locate(modularRoot(), 'Blog\\Listeners\\Notify');

    expect($file)->toEndWith('plugins/blog/src/Listeners/Notify.php');
});

it('reads PSR-4 maps from configured composer files', function () {
    $root = modularRoot();
    $map = ComposerPsr4Map::fromAppRoot($root, ['plugins/*/composer.json'], includeRoot: false);

    expect($map->directories())->toBe(['plugins/blog/src']);
});

it('scans the directories of configured composer files', function () {
    $scope = ScanScope::fromConfig(['composer_files' => ['plugins/*/composer.json']], modularRoot());

    expect($scope->paths())->toBe(['app', 'plugins/blog/src']);
});

it('reports PSR-4 directories of nested composer files that no scan path covers', function () {
    $scope = ScanScope::fromConfig([], modularRoot());

    expect(ScanCoverage::uncovered(modularRoot(), $scope))->toBe(['plugins/blog/src' => 'plugins/blog/composer.json']);
    expect(ScanCoverage::uncovered(modularRoot(), ScanScope::fromConfig(['composer_files' => ['plugins/*/composer.json']], modularRoot())))->toBe([]);
});

it('rejects a relative app root', function () {
    (new IndexBuilder)->build('../relative/root', '12.0.0');
})->throws(InvalidArgumentException::class, 'absolute');
