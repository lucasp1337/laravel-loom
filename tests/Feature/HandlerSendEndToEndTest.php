<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

/**
 * @return array{0: IndexBuilder, 1: array<string, mixed>}
 */
function buildHandlerSendPayload(): array
{
    $builder = new IndexBuilder;
    foreach (DefaultScanners::all() as $scanner) {
        $builder->register($scanner);
    }

    return [$builder, $builder->build(dirname(__DIR__).'/Fixtures/handler-send-fixture-app', '12.x')->toArray()];
}

it('scans mail/notification sends made from handlers into a schema-valid index', function () {
    [$builder, $payload] = buildHandlerSendPayload();

    expect($builder->validate($payload))->toBe([]);
});

it('keeps mailable/notification kinds out of every handler dispatches[]', function () {
    [, $payload] = buildHandlerSendPayload();

    $kinds = [];
    foreach (['listeners', 'jobs', 'observers', 'routes', 'closure_listeners'] as $section) {
        foreach ($payload[$section] ?? [] as $entry) {
            foreach ($entry['dispatches'] ?? [] as $dispatch) {
                $kinds[] = $dispatch['kind'];
            }
        }
    }

    expect(array_diff($kinds, ['event', 'job']))->toBe([]);
});

it('still records handler sends in sent_from and notified_from', function () {
    [, $payload] = buildHandlerSendPayload();

    $mail = collect($payload['mailables'])->firstWhere('fqcn', 'App\\Mail\\OrderReceipt');
    $notice = collect($payload['notifications'])->firstWhere('fqcn', 'App\\Notifications\\OrderNotice');

    $mailFrom = array_column($mail['sent_from'], 'method');
    $noticeFrom = array_column($notice['notified_from'], 'method');

    foreach (['Listeners\\SendReceipt', 'SendReceiptJob', 'UserObserver', 'OrderController'] as $short) {
        expect(collect($mailFrom)->contains(fn ($c) => str_contains((string) $c, $short.'::')))->toBeTrue("mail from $short");
        expect(collect($noticeFrom)->contains(fn ($c) => str_contains((string) $c, $short.'::')))->toBeTrue("notice from $short");
    }
});
