<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ComposerPsr4Map;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScanScope;

function scopeFixture(string $name): string
{
    return dirname(__DIR__).'/Fixtures/'.$name;
}

/**
 * @return array<string, mixed>
 */
function scanWithScope(string $fixture, ScanScope $scope): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, $scope);

    return $builder->build(scopeFixture($fixture), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @return list<string>
 */
function fqcns(array $entries, string $key = 'fqcn'): array
{
    $values = array_map(fn (array $entry): string => $entry[$key], $entries);
    sort($values);

    return $values;
}

it('scans a Modules/Billing layout with a glob scan path', function () {
    $payload = scanWithScope('modules-fixture-app', new ScanScope(['Modules/*']));

    expect(fqcns($payload['events']))->toBe(['Modules\Billing\Events\InvoicePaid'])
        ->and(fqcns($payload['listeners']))->toBe(['Modules\Billing\Listeners\SendReceipt'])
        ->and(fqcns($payload['jobs']))->toBe(['Modules\Billing\Jobs\ChargeCard'])
        ->and(fqcns($payload['mailables']))->toBe(['Modules\Billing\Mail\ReceiptMail'])
        ->and(fqcns($payload['notifications']))->toBe(['Modules\Billing\Notifications\InvoiceOverdue'])
        ->and($payload['unresolved_dispatches'])->toHaveCount(1)
        ->and($payload['unresolved_dispatches'][0]['file'])->toBe('Modules/Billing/Services/InvoiceService.php');
});

it('links dispatches across a module layout', function () {
    $payload = scanWithScope('modules-fixture-app', new ScanScope(['Modules/*']));

    $event = $payload['events'][0];
    $files = array_map(fn (array $site): string => $site['file'], $event['dispatched_from']);
    sort($files);

    expect($files)->toBe([
        'Modules/Billing/Legacy/OldJob.php',
        'Modules/Billing/Services/InvoiceService.php',
    ])->and($payload['jobs'][0]['dispatched_from'][0]['file'])->toBe('Modules/Billing/Listeners/SendReceipt.php');
});

it('scans src/Domain with a custom namespace', function () {
    $payload = scanWithScope('domain-fixture-app', new ScanScope(['src/Domain']));

    expect(fqcns($payload['events']))->toBe(['Acme\Shop\Events\OrderPlaced'])
        ->and(fqcns($payload['listeners']))->toBe(['Acme\Shop\Listeners\ReserveStock'])
        ->and(fqcns($payload['jobs']))->toBe(['Acme\Shop\Jobs\ShipOrder'])
        ->and($payload['events'][0]['dispatched_from'][0]['file'])->toBe('src/Domain/Checkout.php')
        ->and($payload['jobs'][0]['dispatched_from'][0]['file'])->toBe('src/Domain/Checkout.php');
});

it('adds composer psr-4 directories when psr4_paths is on', function () {
    $scope = ScanScope::fromConfig(['paths' => ['app'], 'psr4_paths' => true], scopeFixture('domain-fixture-app'));

    expect($scope->paths())->toBe(['app', 'src/Domain'])
        ->and(fqcns(scanWithScope('domain-fixture-app', $scope)['events']))->toBe(['Acme\Shop\Events\OrderPlaced']);
});

it('leaves psr-4 directories out by default', function () {
    $payload = scanWithScope('domain-fixture-app', ScanScope::fromConfig([], scopeFixture('domain-fixture-app')));

    expect($payload['events'])->toBe([]);
});

it('removes excluded files from every scanner', function () {
    $payload = scanWithScope('modules-fixture-app', new ScanScope(['Modules/*'], ['Modules/*/Legacy/**', '**/Jobs', 'Modules/Billing/Mail/*.php']));

    expect($payload['jobs'])->toBe([])
        ->and($payload['mailables'])->toBe([])
        ->and(array_map(fn (array $site): string => $site['file'], $payload['events'][0]['dispatched_from']))
        ->toBe(['Modules/Billing/Services/InvoiceService.php']);
});

it('does not resolve classes through psr-4 into excluded or unscanned files', function () {
    $fixture = scopeFixture('modules-fixture-app');

    $scoped = scanWithScope('modules-fixture-app', new ScanScope(['Modules/Billing/Services']));

    expect($scoped['events'])->toBe([])
        ->and((new Psr4ClassLocator)->locate($fixture, 'Modules\Billing\Events\InvoicePaid'))
        ->toBe($fixture.'/Modules/Billing/Events/InvoicePaid.php');
});

it('keeps the default scope on the app directory', function () {
    $payload = scanWithScope('modules-fixture-app', ScanScope::default());

    expect($payload['events'])->toBe([])->and($payload['jobs'])->toBe([]);
});

it('rejects unsafe scan paths', function (string $path) {
    new ScanScope([$path]);
})->with(['/etc', '../outside', 'app/../../x', '', 'C:\\temp'])->throws(InvalidArgumentException::class);

it('matches exclude globs against files and parent directories', function () {
    $root = '/project';
    $scope = new ScanScope(['app'], ['app/Legacy', 'app/**/Fakes/*.php', '*.generated.php']);

    expect($scope->isExcluded($root, '/project/app/Legacy/Deep/File.php'))->toBeTrue()
        ->and($scope->isExcluded($root, '/project/app/A/B/Fakes/X.php'))->toBeTrue()
        ->and($scope->isExcluded($root, '/project/app/Fakes/X.php'))->toBeTrue()
        ->and($scope->isExcluded($root, '/project/Foo.generated.php'))->toBeTrue()
        ->and($scope->isExcluded($root, '/project/app/Legacy2/File.php'))->toBeFalse()
        ->and($scope->isExcluded($root, '/project/app/Models/User.php'))->toBeFalse();
});

describe('ComposerPsr4Map', function () {
    it('falls back to App\\ => app/ without composer.json', function () {
        $map = ComposerPsr4Map::fromAppRoot(scopeFixture('jobs-fixture-app'));

        expect($map->directories())->toBe(['app']);
    });

    it('prefers the longest matching prefix', function () {
        $map = new ComposerPsr4Map([
            'Modules\\' => ['Modules'],
            'Modules\\Billing\\' => ['Modules/Billing'],
        ]);

        expect($map->locate(scopeFixture('modules-fixture-app'), 'Modules\Billing\Jobs\ChargeCard'))
            ->toBe(scopeFixture('modules-fixture-app').'/Modules/Billing/Jobs/ChargeCard.php');
    });

    it('supports directory arrays and the empty fallback prefix', function () {
        $root = scopeFixture('domain-fixture-app');
        $map = new ComposerPsr4Map(['' => ['src/Domain/Events'], 'Nope\\' => ['x', 'src']]);

        expect($map->locate($root, 'OrderPlaced'))->toBe($root.'/src/Domain/Events/OrderPlaced.php')
            ->and($map->locate($root, 'Nope\Domain\Checkout'))->toBe($root.'/src/Domain/Checkout.php');
    });
});

it('reads module routes only from configured route paths', function () {
    $default = scanWithScope('modules-fixture-app', new ScanScope(['Modules/*']));
    expect($default['routes'])->toBe([]);

    $scope = new ScanScope(['Modules/*'], [], ['routes', 'Modules/*/routes']);
    $payload = scanWithScope('modules-fixture-app', $scope);

    expect(array_column($payload['routes'], 'uri'))->toBe(['/billing/paid']);
    expect($payload['routes'][0]['file'])->toBe('Modules/Billing/routes/web.php');
    expect(array_column($payload['routes'][0]['dispatches'], 'target'))->toBe(['Modules\Billing\Events\InvoicePaid']);
});

it('lets exclude globs remove route files', function () {
    $scope = new ScanScope(['Modules/*'], ['Modules/*/routes'], ['Modules/*/routes']);

    expect(scanWithScope('modules-fixture-app', $scope)['routes'])->toBe([]);
});

it('rejects an absolute or escaping route path', function () {
    expect(fn () => new ScanScope(['app'], [], ['/etc']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ScanScope(['app'], [], ['../x']))->toThrow(InvalidArgumentException::class);
});
