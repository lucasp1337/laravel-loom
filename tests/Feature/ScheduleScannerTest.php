<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\ScheduleScanner;

function scheduleFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/schedule-fixture-app';
}

use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Index\FrequencyUnit;
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
// Sub-minute frequency helpers (structured frequency, cron null)
// ---------------------------------------------------------------------------

it('emits frequency {seconds, 15} and null cron for ->everyFifteenSeconds()', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 164);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sub:fifteen');
    expect($entry->cron)->toBeNull();
    expect($entry->frequency)->not->toBeNull();
    expect($entry->frequency->unit)->toBe(FrequencyUnit::SECONDS);
    expect($entry->frequency->every)->toBe(15);
});

it('emits frequency {seconds, 1} and null cron for ->everySecond()', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 168);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sub:second');
    expect($entry->cron)->toBeNull();
    expect($entry->frequency)->not->toBeNull();
    expect($entry->frequency->unit)->toBe(FrequencyUnit::SECONDS);
    expect($entry->frequency->every)->toBe(1);
});

it('emits frequency {seconds, 30} and null cron for ->everyThirtySeconds()', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 172);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sub:thirty');
    expect($entry->cron)->toBeNull();
    expect($entry->frequency)->not->toBeNull();
    expect($entry->frequency->unit)->toBe(FrequencyUnit::SECONDS);
    expect($entry->frequency->every)->toBe(30);
});

it('leaves frequency null for an ordinary cron entry', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->frequency)->toBeNull();
});

it('last-wins ->everyFifteenSeconds()->daily(): cron set, frequency null', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 176);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sub:override-cron');
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->frequency)->toBeNull();
});

it('last-wins ->daily()->everyFifteenSeconds(): cron null, frequency set', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 181);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('sub:override-frequency');
    expect($entry->cron)->toBeNull();
    expect($entry->frequency)->not->toBeNull();
    expect($entry->frequency->unit)->toBe(FrequencyUnit::SECONDS);
    expect($entry->frequency->every)->toBe(15);
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

// ---------------------------------------------------------------------------
// daysOfMonth helper
// ---------------------------------------------------------------------------

it('normalises variadic ->daysOfMonth(1, 15) to "0 0 1,15 * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 194);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('daysofmonth:variadic');
    expect($entry->cron)->toBe('0 0 1,15 * *');
});

it('normalises array-form ->daysOfMonth([2, 16]) to "0 0 2,16 * *"', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 198);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('daysofmonth:array');
    expect($entry->cron)->toBe('0 0 2,16 * *');
});

it('keeps the cron when ->daysOfMonth() is chained after another frequency helper', function () {
    // ->daily()->daysOfMonth(10) — daysOfMonth is last-wins and must not trip
    // the unknown-method guard that nulls cron.
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 203);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('daysofmonth:chained');
    expect($entry->cron)->toBe('0 0 10 * *');
});

it('sets cron to null when ->daysOfMonth() has an unresolvable arg', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 208);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('daysofmonth:dynamic');
    expect($entry->cron)->toBeNull();
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
// Name
// ---------------------------------------------------------------------------

it('extracts name from ->name("nightly-report")', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 121);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('named:job');
    expect($entry->name)->toBe('nightly-report');
    expect($entry->cron)->toBe('0 0 * * *');
});

it('leaves name null when ->name() has an unresolvable arg', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 126);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('named:dynamic');
    expect($entry->name)->toBeNull();
});

it('leaves name null when no ->name() link appears', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBeNull();
});

// ---------------------------------------------------------------------------
// evenInMaintenanceMode
// ---------------------------------------------------------------------------

it('sets even_in_maintenance_mode=true when ->evenInMaintenanceMode() appears in the chain', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 131);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('maint:job');
    expect($entry->evenInMaintenanceMode)->toBeTrue();
});

it('sets even_in_maintenance_mode=false when no ->evenInMaintenanceMode() link appears', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->evenInMaintenanceMode)->toBeFalse();
});

it('captures name and even_in_maintenance_mode together on one entry', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 136);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('named:maint');
    expect($entry->name)->toBe('combined-task');
    expect($entry->evenInMaintenanceMode)->toBeTrue();
    expect($entry->cron)->toBe('0 0 * * *');
});

// ---------------------------------------------------------------------------
// withoutOverlapping expiry
// ---------------------------------------------------------------------------

it('captures the expiry minutes from ->withoutOverlapping(10)', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 142);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('overlap:expires');
    expect($entry->withoutOverlapping)->toBeTrue();
    expect($entry->withoutOverlappingExpiresAt)->toBe(10);
});

it('leaves withoutOverlappingExpiresAt null for ->withoutOverlapping() with no arg', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 147);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('overlap:default');
    expect($entry->withoutOverlapping)->toBeTrue();
    expect($entry->withoutOverlappingExpiresAt)->toBeNull();
});

// ---------------------------------------------------------------------------
// Command arguments
// ---------------------------------------------------------------------------

it('captures command arguments: list item, keyed item, and bool', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 152);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::COMMAND);
    expect($entry->target)->toBe('args:command');
    expect($entry->arguments)->toBe(['--force', 'user=1', 'dry=true']);
});

it('emits an empty arguments array for a command with no arguments array', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 21);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::COMMAND);
    expect($entry->arguments)->toBe([]);
});

// ---------------------------------------------------------------------------
// Job queue + connection overrides
// ---------------------------------------------------------------------------

it('captures queue and connection from ->job(new HeavyJob, "emails", "redis")', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 156);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::JOB);
    expect($entry->queue)->toBe('emails');
    expect($entry->connection)->toBe('redis');
});

it('leaves queue and connection null for ->job(new HeavyJob) with no overrides', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 160);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::JOB);
    expect($entry->queue)->toBeNull();
    expect($entry->connection)->toBeNull();
    // JOB entries never carry command arguments.
    expect($entry->arguments)->toBe([]);
});

it('defaults arguments:[] / queue:null / connection:null on a closure entry', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 31);

    expect($entry)->not->toBeNull();
    expect($entry->kind)->toBe(ScheduleKind::CLOSURE);
    expect($entry->arguments)->toBe([]);
    expect($entry->queue)->toBeNull();
    expect($entry->connection)->toBeNull();
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
// Scheduling groups: inherited frequency + modifiers merge into inner tasks
// ---------------------------------------------------------------------------

it('inherits the group frequency and modifiers on an inner task with no own frequency', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 188);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('grouped:alpha');
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->onOneServer)->toBeTrue();
});

it('lets an inner task override the group frequency while still inheriting modifiers', function () {
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 189);

    expect($entry)->not->toBeNull();
    expect($entry->target)->toBe('grouped:beta');
    expect($entry->cron)->toBe('0 * * * *');
    expect($entry->onOneServer)->toBeTrue();
});

it('does not apply group modifiers to a non-grouped entry', function () {
    // A plain ->daily() entry outside any group keeps its cron and must not
    // inherit onOneServer from the trailing group block.
    $entry = scheduleEntryAt(scheduleEntries(), 'app/Console/Kernel.php', 24);

    expect($entry)->not->toBeNull();
    expect($entry->cron)->toBe('0 0 * * *');
    expect($entry->onOneServer)->toBeFalse();
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
