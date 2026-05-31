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

it('resolves chain-wrapped mailable dispatches into sent_from (issue #31)', function () {
    // Regression for issue #31: Mail::to($user)->send((new OrderShipped())->locale('fr'))
    // wraps the mailable in a leading fluent MethodCall chain. The chain must be
    // unwrapped so the FQCN resolves and the site lands in sent_from[], NOT
    // unresolved_dispatches[].
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\OrderShipped');
    expect($entry)->not->toBeNull();

    $lines = array_column($entry['sent_from'], 'line');

    // Mail::to($user)->send((new OrderShipped())->locale('fr'))
    expect($lines)->toContain(39);

    // The chained site must NOT have leaked into unresolved_dispatches.
    $unresolvedLines = array_column($payload['unresolved_dispatches'], 'line');
    expect($unresolvedLines)->not->toContain(39);
});

it('captures Mail facade-receiver locale/mailer overrides in sent_from (issue #32)', function () {
    // MailDispatcher::dispatchWithOverrides (separate fixture class so the
    // OrderShipped/WelcomeEmail count + method assertions stay untouched):
    //   Mail::to($u)->locale('fr')->mailer('ses')->send(new IndirectlyQueuedMail())
    // is the (b) facade-receiver chain. The locale/mailer modifiers sit on the
    // Mail::to(...) receiver chain ahead of the terminal send(); they must land
    // as an overrides object on the IndirectlyQueuedMail sent_from entry.
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\IndirectlyQueuedMail');
    expect($entry)->not->toBeNull();

    $site = array_values(array_filter(
        $entry['sent_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\MailDispatcher::dispatchWithOverrides'
            && ($s['line'] ?? null) === 22,
    ));

    expect($site)->toHaveCount(1);
    expect($site[0]['overrides'])->toBe(['locale' => 'fr', 'mailer' => 'ses']);
});

it('captures the #31 inner-chain locale override and omits overrides on plain sends (issue #32)', function () {
    // The #31 chain-wrapped send at Checkout::finalize line 39 is
    // Mail::to($user)->send((new OrderShipped())->locale('fr')) — positive
    // coverage for the inner (a) chain locale override. The plain facade sends
    // (e.g. line 16 Mail::send(new OrderShipped())) must carry NO overrides key.
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\OrderShipped');
    expect($entry)->not->toBeNull();

    $byLine = [];
    foreach ($entry['sent_from'] as $s) {
        $byLine[$s['line']] = $s;
    }

    // #31 inner-chain site at line 39 now carries an overrides object.
    expect($byLine[39]['overrides'])->toBe(['locale' => 'fr']);

    // Plain facade send at line 16 has no modifiers -> no overrides key.
    expect($byLine[16])->not->toHaveKey('overrides');
});

it('omits overrides for the no-modifier facade-receiver send (issue #32)', function () {
    // Mail::to($user)->send(new IndirectlyQueuedMail()) at MailDispatcher line 26
    // is a facade-receiver chain with no modifier links; the entry must be
    // byte-identical to a plain send (no overrides key).
    $payload = buildMailableEndToEndPayload();

    $entry = mailableEntryByFqcn($payload['mailables'], 'App\\Mail\\IndirectlyQueuedMail');
    expect($entry)->not->toBeNull();

    $site = array_values(array_filter(
        $entry['sent_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\MailDispatcher::dispatchWithOverrides'
            && ($s['line'] ?? null) === 26,
    ));

    expect($site)->toHaveCount(1);
    expect($site[0])->not->toHaveKey('overrides');
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
