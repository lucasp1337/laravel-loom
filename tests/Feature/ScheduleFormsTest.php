<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Scanners\ScheduleScanner;
use Lucasp\Loom\Support\Ast\AstWalker;

/**
 * @return array<string, ScheduledEntry> keyed by target
 */
function scheduleFormEntries(): array
{
    $entries = (new ScheduleScanner)->scan(dirname(__DIR__).'/Fixtures/schedule-forms-fixture-app')['scheduled_tasks'];

    return collect($entries)->keyBy(fn (ScheduledEntry $e): string => (string) $e->target)->all();
}

it('reads a job scheduled through a property that holds the Schedule', function () {
    $entry = scheduleFormEntries()['App\\Jobs\\Prune'];

    expect($entry->kind)->toBe(ScheduleKind::JOB);
    expect($entry->cron)->toBe('0 * * * *');
});

it('reads helper methods that take the Schedule as a parameter', function () {
    expect(scheduleFormEntries()['reports:daily']->cron)->toBe('0 0 * * *');
});

it('treats at() as dailyAt and keeps the day fields of the preceding helper', function () {
    $entry = scheduleFormEntries()['report'];

    expect($entry->cron)->toBe('15 3 * * 0');
    expect($entry->constraints)->toBe(['sundays']);
});

it('reads Artisan::command() chains that schedule the closure command', function () {
    $entries = scheduleFormEntries();

    expect($entries['inspire']->kind)->toBe(ScheduleKind::COMMAND);
    expect($entries['inspire']->cron)->toBe('0 * * * *');
    expect($entries)->not->toHaveKey('plain');
});

it('records enclosing if branches as constraints', function () {
    $entries = scheduleFormEntries();

    expect($entries['alerts']->constraints)->toBe(["if(config('alerts.enabled'))"]);
    expect($entries['alerts:legacy']->constraints)->toBe(["elseif(config('alerts.legacy'))"]);
    expect($entries['alerts:off']->constraints)->toBe(["else(config('alerts.enabled'))"]);
});

it('reports a schedule definition whose tasks cannot be read', function () {
    $walker = new AstWalker;
    $root = dirname(__DIR__).'/Fixtures/schedule-unread-fixture-app';

    $result = (new ScheduleScanner($walker))->scan($root);

    expect($result['scheduled_tasks'])->toBe([]);
    $skipped = $walker->skippedFiles();
    expect($skipped)->toHaveCount(1);
    expect($skipped[0]->file)->toEndWith('app/Console/Kernel.php');
    expect($skipped[0]->line)->toBe(11);
});

it('reports a Schedule helper that schedule() never calls', function () {
    $walker = new AstWalker;

    (new ScheduleScanner($walker))->scan(dirname(__DIR__).'/Fixtures/schedule-fixture-app');

    $notes = collect($walker->skippedFiles())->filter(fn ($s): bool => str_ends_with($s->file, 'app/Console/Kernel.php'));
    expect($notes)->toHaveCount(1);
    expect($notes->first()->message)->toContain('never called from schedule()');
});
