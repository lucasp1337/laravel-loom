<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;

/**
 * @return array<string, mixed>
 */
function providerScopeScan(ScanScope $scope): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, $scope);

    return $builder->build(dirname(__DIR__).'/Fixtures/provider-scope-fixture-app', '12.x')->toArray();
}

it('reads registrations in a listed provider outside the scan paths', function () {
    $index = providerScopeScan(new ScanScope(['app']));

    $listeners = collect($index['listeners'])->keyBy('fqcn');
    expect($listeners->keys()->all())->toBe(['App\\Billing\\ChargeCustomer'])
        ->and($listeners['App\\Billing\\ChargeCustomer']['registration'])->toBe('event_listen_call')
        ->and(collect($index['observers'])->pluck('fqcn')->all())->toBe(['App\\Observers\\InvoiceObserver'])
        ->and(collect($index['scheduled_tasks'])->map(fn (array $task): string => $task['file'].' '.$task['target'])->all())
        ->toBe(['Modules/Billing/BillingServiceProvider.php invoices:close']);
});

it('skips an excluded provider', function () {
    $index = providerScopeScan(new ScanScope(['app'], ['Modules/Billing']));

    expect($index['listeners'])->toBe([])
        ->and($index['observers'])->toBe([])
        ->and($index['scheduled_tasks'])->toBe([]);
});
