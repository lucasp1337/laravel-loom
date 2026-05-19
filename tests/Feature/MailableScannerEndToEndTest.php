<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\MailableScanner;

function mailableEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/mailable-fixture-app';
}

/**
 * @return array<string, mixed>
 */
function buildMailableEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new MailableScanner);
    $builder->register(new DispatchScanner);

    return $builder->build(mailableEndToEndFixturePath(), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @return array<string, mixed>|null
 */
function mailableEntryByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('produces a schema-valid index with MailableScanner + DispatchScanner registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new MailableScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(mailableEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('reports stats.mailables equal to the count of mailable entries', function () {
    $payload = buildMailableEndToEndPayload();

    expect($payload)->toHaveKey('mailables');
    expect($payload['stats'])->toHaveKey('mailables');
    expect($payload['stats']['mailables'])->toBe(count($payload['mailables']));
    expect($payload['stats']['mailables'])->toBeGreaterThan(0);
});

it('includes the known mailable FQCNs in the built index', function () {
    $payload = buildMailableEndToEndPayload();

    $fqcns = array_column($payload['mailables'], 'fqcn');

    expect($fqcns)->toContain('App\\Mail\\OrderShipped');
    expect($fqcns)->toContain('App\\Mail\\WelcomeEmail');
    expect($fqcns)->toContain('App\\Mail\\IndirectlyQueuedMail');
    expect($fqcns)->toContain('App\\Domain\\Billing\\Mail\\InvoiceMailable');
});

it('populates mailables[OrderShipped].sent_from with every recognised dispatch shape', function () {
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\OrderShipped');
    expect($entry)->not->toBeNull();
    expect($entry['sent_from'])->not->toBe([]);

    $methods = array_column($entry['sent_from'], 'method');
    // Every site lives in Checkout::finalize.
    foreach ($methods as $method) {
        expect($method)->toBe('App\\Services\\Checkout::finalize');
    }

    $lines = array_column($entry['sent_from'], 'line');
    sort($lines);

    // OrderShipped is dispatched four times in the fixture (Mail::send,
    // Mail::later, two chain forms on Mail::to(...)->send).
    expect(count($lines))->toBeGreaterThanOrEqual(4);

    // The chain forms (Mail::to(...)->send(...) and the multi-link chain)
    // MUST appear in sent_from, not just the direct facade calls.
    expect($lines)->toContain(22); // Mail::to($user)->send(new OrderShipped())
    expect($lines)->toContain(24); // Mail::to($user)->cc(...)->bcc(...)->send(...)
    expect($lines)->toContain(33); // Mail::send(OrderShipped::class) — class-constant form
});

it('populates mailables[WelcomeEmail].sent_from for queue and chain-with-locale forms', function () {
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\WelcomeEmail');
    expect($entry)->not->toBeNull();
    expect($entry['sent_from'])->not->toBe([]);

    $lines = array_column($entry['sent_from'], 'line');
    sort($lines);

    expect($lines)->toContain(18); // Mail::queue(new WelcomeEmail())
    expect($lines)->toContain(26); // Mail::to($user)->locale('fr')->send(new WelcomeEmail())
});

it('populates mailables[InvoiceMailable].sent_from for the PSR-4 seeded mailable', function () {
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Domain\\Billing\\Mail\\InvoiceMailable');
    expect($entry)->not->toBeNull();
    expect($entry['sent_from'])->not->toBe([]);

    $methods = array_column($entry['sent_from'], 'method');
    expect($methods)->toContain('App\\Services\\Checkout::finalize');
});

it('emits an unresolved_dispatches entry for Mail::send($dynamicMailable) and no mailables row for it', function () {
    $payload = buildMailableEndToEndPayload();

    // The dynamic dispatch should NOT create a mailables[] row.
    $fqcns = array_column($payload['mailables'], 'fqcn');
    expect($fqcns)->not->toContain('dynamicMailable');

    // It SHOULD appear in unresolved_dispatches with a recognised reason code.
    $unresolved = $payload['unresolved_dispatches'];
    expect($unresolved)->not->toBe([]);

    $files = array_column($unresolved, 'file');
    $forwardSlashed = array_map(
        static fn (string $f): string => str_replace(DIRECTORY_SEPARATOR, '/', $f),
        $files,
    );
    expect($forwardSlashed)->toContain('app/Services/Checkout.php');
});

it('sorts mailables by fqcn ascending in the built index', function () {
    $payload = buildMailableEndToEndPayload();

    /** @var array<int, array<string, mixed>> $mailables */
    $mailables = $payload['mailables'];

    $fqcns = array_column($mailables, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});
