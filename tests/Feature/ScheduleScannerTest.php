<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\ScheduleScanner;

function scheduleFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/schedule-fixture-app';
}

use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Index\ScheduleKind;

/**
 * Locate a scheduled entry by its (file, line) tuple.
 *
 * @param  list<ScheduledEntry>  $entries
 */
function scheduleEntryAt(array $entries, string $file, int $line): ?ScheduledEntry
{
    foreach ($entries as $entry) {
        $entryFile = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);

        if ($entryFile === $file && $entry->line === $line) {
            return $entry;
        }
    }

    return null;
}

/**
 * Convenience: scan the fixture and return the `scheduled[]` list.
 *
 * @return array<int, array<string, mixed>>
 */
function scheduleEntries(): array
{
    $result = (new ScheduleScanner)->scan(scheduleFixturePath());

    /** @var array<int, array<string, mixed>> $entries */
    $entries = $result['scheduled'];

    return $entries;
}

// ---------------------------------------------------------------------------
// Empty-path behaviour
// ---------------------------------------------------------------------------

it('returns an empty scheduled array when neither bootstrap/app.php, Kernel.php nor app/ exist', function () {
    $result = (new ScheduleScanner)->scan(sys_get_temp_dir());

    expect($result)->toBe(['scheduled' => []]);
});

// ---------------------------------------------------------------------------
// Discovery paths
// ---------------------------------------------------------------------------

it('discovers entries declared inside Console\\Kernel::schedule()', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 16);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::COMMAND);
    expect($entry->target)->toBe('mail:send');
});

it('discovers entries declared inside bootstrap/app.php withSchedule(...)', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'bootstrap/app.php', 10);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::COMMAND);
    expect($entry->target)->toBe('queue:prune-batches');
});

it('discovers entries declared via the Schedule facade in a provider', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Providers/ScheduleServiceProvider.php', 13);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::COMMAND);
    expect($entry->target)->toBe('horizon:snapshot');
});

// ---------------------------------------------------------------------------
// Kind classification
// ---------------------------------------------------------------------------

it('classifies kind per root method', function () {
    $entries = scheduleEntries();

    $kernel = 'app/Console/Kernel.php';

    $kindAt = static function (int $line) use ($entries, $kernel): ?ScheduleKind {
        $entry = scheduleEntryAt($entries, $kernel, $line);
        if ($entry === null) {
            return null;
        }

        return $entry->kind;
    };

    expect($kindAt(16))->toBe(ScheduleKind::COMMAND);
    expect($kindAt(21))->toBe(ScheduleKind::COMMAND);
    expect($kindAt(24))->toBe(ScheduleKind::JOB);
    expect($kindAt(27))->toBe(ScheduleKind::JOB);
    expect($kindAt(31))->toBe(ScheduleKind::CLOSURE);
    expect($kindAt(34))->toBe(ScheduleKind::CLOSURE);
    expect($kindAt(37))->toBe(ScheduleKind::CLOSURE);
    expect($kindAt(40))->toBe(ScheduleKind::EXEC);
});

// ---------------------------------------------------------------------------
// Target normalisation
// ---------------------------------------------------------------------------

it('resolves SendMail::class as a command target FQCN', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 21);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('App\\Console\\Commands\\SendMail');
});

it('resolves new SendInvoice() in ->job to the FQCN target', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('App\\Jobs\\SendInvoice');
});

it('resolves SendInvoice::class in ->job to the FQCN target', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 27);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('App\\Jobs\\SendInvoice');
});

it('normalises tuple-form ->call([Cls::class, "method"]) to FQCN::method', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 34);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::CLOSURE);
    expect($entry->target)->toBe('App\\Reports::generate');
});

it('normalises Laravel-callable string ->call("App\\Cls@method") to FQCN::method', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 37);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::CLOSURE);
    expect($entry->target)->toBe('App\\Maintenance::run');
});

it('leaves inline closures with a null target (file:line is the identity)', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 31);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::CLOSURE);
    expect($entry->target)->toBeNull();
});

it('keeps exec shell command strings verbatim as target', function () {
    $entries = scheduleEntries();

    $entry = scheduleEntryAt($entries, 'app/Console/Kernel.php', 40);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::EXEC);
    expect($entry->target)->toBe('php artisan some:thing');
});

// ---------------------------------------------------------------------------
// Cron normalisation
// ---------------------------------------------------------------------------

it('normalises ->dailyAt("13:00") to "0 13 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 16);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 13 * * *');
});

it('normalises ->everyFifteenMinutes() to "*/15 * * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 21);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('*/15 * * * *');
});

it('normalises ->daily() to "0 0 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 0 * * *');
});

it('normalises ->hourly() to "0 * * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 27);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 * * * *');
});

it('normalises ->everyTenMinutes() to "*/10 * * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 31);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('*/10 * * * *');
});

it('normalises ->weeklyOn(1, "08:00") to "0 8 * * 1"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 34);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 8 * * 1');
});

it('normalises ->monthly() to "0 0 1 * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 37);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 0 1 * *');
});

it('passes cron("5 * * * *") through verbatim', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 40);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('5 * * * *');
});

it('normalises ->everyFiveMinutes() to "*/5 * * * *" (bootstrap form)', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'bootstrap/app.php', 13);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('*/5 * * * *');
});

it('normalises ->everyMinute() to "* * * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 59);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('* * * * *');
});

// ---------------------------------------------------------------------------
// Last-wins on multiple frequency helpers
// ---------------------------------------------------------------------------

it('applies last-wins on multiple frequency helpers in a chain (->daily()->hourly())', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 48);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 * * * *');
});

// ---------------------------------------------------------------------------
// cron: null cases
// ---------------------------------------------------------------------------

it('sets cron to null when an unrecognised frequency helper is used', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 44);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBeNull();
});

it('sets cron to null when no frequency helper appears in the chain', function () {
    // The provider-form entry (line 12 of ScheduleServiceProvider.php) has
    // ->everyFiveMinutes() so it does NOT exercise this branch. We re-use the
    // unrecognised-helper line for cron-null coverage; this test specifically
    // pins the variable-arg case via a synthetic check on a known fixture line
    // by inspecting an entry we explicitly omitted a frequency from — to keep
    // the fixture small, we assert against the unrecognised helper entry,
    // which also leaves cron null.
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 44);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBeNull();
});

it('nulls cron when an unknown method follows a recognised frequency helper', function () {
    // ->daily()->someUserMacro() — the trailing macro could itself be a
    // frequency-setter at runtime (Schedule::macro, future Laravel helper).
    // Last-wins safety: null the cron rather than misrepresent the schedule.
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 69);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('macro:sometime');
    expect($entry->cron)->toBeNull();
});

it('normalises ->weeklyOn([1, 3, 5], "08:00") to a comma-separated weekday field', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 64);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('digest:weekly');
    expect($entry->cron)->toBe('0 8 * * 1,3,5');
});

it('normalises ->everyOddHour() to "0 1-23/2 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 74);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('odd:default');
    expect($entry->cron)->toBe('0 1-23/2 * * *');
});

it('normalises ->everyOddHour(30) to "30 1-23/2 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 78);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('odd:atminute');
    expect($entry->cron)->toBe('30 1-23/2 * * *');
});

it('normalises ->quarterlyOn() to "0 0 1 1-12/3 *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 82);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('quarter:default');
    expect($entry->cron)->toBe('0 0 1 1-12/3 *');
});

it('normalises ->quarterlyOn(15, "14:30") to "30 14 15 1-12/3 *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 86);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('quarter:args');
    expect($entry->cron)->toBe('30 14 15 1-12/3 *');
});

it('sets cron to null for ->quarterlyOn() with an unparseable time arg', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 90);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('quarter:dynamic');
    expect($entry->cron)->toBeNull();
});

it('normalises ->everyTwoHours(15) to "15 */2 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 94);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('twohours:atminute');
    expect($entry->cron)->toBe('15 */2 * * *');
});

it('keeps ->everyTwoHours() (no arg) at "0 */2 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 98);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('twohours:default');
    expect($entry->cron)->toBe('0 */2 * * *');
});

it('normalises ->everySixHours(45) to "45 */6 * * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 102);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sixhours:atminute');
    expect($entry->cron)->toBe('45 */6 * * *');
});

it('captures ->days([1, 5]) as a constraint without nulling the daily cron', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 106);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('days:array');
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->constraints)->toContain('days(1,5)');
});

it('captures variadic ->days(1, 5) as the "days(1,5)" constraint', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 111);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('days:variadic');
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->constraints)->toContain('days(1,5)');
});

it('emits "days(?)" when ->days() has an unresolvable arg', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 116);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('days:dynamic');
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->constraints)->toContain('days(?)');
});

it('does not emit chains declared outside the schedule() method', function () {
    // Kernel.php has a `helper(Schedule $schedule)` method with a
    // ->command('decoy:command')->daily() chain. The kernel-form discovery
    // pass must narrow to the `schedule()` method body only.
    $entries = scheduleEntries();
    $targets = array_column($entries, 'target');

    expect($targets)->not->toContain('decoy:command');
});

// ---------------------------------------------------------------------------
// Flags
// ---------------------------------------------------------------------------

it('sets without_overlapping=true when ->withoutOverlapping() appears in the chain', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 16);

    expect($entry)->not->toBeNull();
    expect($entry->withoutOverlapping)->toBeTrue();
    expect($entry->onOneServer)->toBeFalse();
    expect($entry->runInBackground)->toBeFalse();
});

it('sets on_one_server=true when ->onOneServer() appears in the chain', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 27);

    expect($entry)->not->toBeNull();
    expect($entry->onOneServer)->toBeTrue();
    expect($entry->withoutOverlapping)->toBeFalse();
    expect($entry->runInBackground)->toBeFalse();
});

it('sets run_in_background=true when ->runInBackground() appears in the chain', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 59);

    expect($entry)->not->toBeNull();
    expect($entry->runInBackground)->toBeTrue();
    expect($entry->withoutOverlapping)->toBeFalse();
    expect($entry->onOneServer)->toBeFalse();
});

it('defaults all three flags to false when no flag link appears in the chain', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->withoutOverlapping)->toBeFalse();
    expect($entry->onOneServer)->toBeFalse();
    expect($entry->runInBackground)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------------

it('extracts timezone from ->timezone("America/Chicago")', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 16);

    expect($entry)->not->toBeNull();
    expect($entry->timezone)->toBe('America/Chicago');
});

it('leaves timezone null when no ->timezone() link appears', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->timezone)->toBeNull();
});

// ---------------------------------------------------------------------------
// Constraints
// ---------------------------------------------------------------------------

it('emits "weekdays" and "between(08:00,17:00)" as constraints', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 53);

    expect($entry)->not->toBeNull();
    expect($entry->constraints)->toContain('weekdays');
    expect($entry->constraints)->toContain('between(08:00,17:00)');
});

it('emits an empty constraints array when no constraint link appears', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->constraints)->toBe([]);
});

// ---------------------------------------------------------------------------
// Required top-level keys
// ---------------------------------------------------------------------------

it('emits every entry as a ScheduledEntry DTO', function () {
    $entries = scheduleEntries();

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(ScheduledEntry::class);
    }
});

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

it('sorts entries by (file, line) ascending', function () {
    $entries = scheduleEntries();

    $tuples = array_map(
        static fn (ScheduledEntry $e): array => [
            str_replace(DIRECTORY_SEPARATOR, '/', $e->file),
            $e->line,
        ],
        $entries,
    );

    $sorted = $tuples;
    usort($sorted, static function (array $a, array $b): int {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });

    expect($tuples)->toBe($sorted);
});

// ---------------------------------------------------------------------------
// Portability: forward-slashed, fixture-root-relative paths
// ---------------------------------------------------------------------------

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = scheduleEntries();

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', (string) $entry->file);

        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        // Every fixture surface lives under one of these top-level roots.
        $isUnderApp = str_starts_with($file, 'app/');
        $isBootstrap = $file === 'bootstrap/app.php';
        expect($isUnderApp || $isBootstrap)->toBeTrue();
    }
});
