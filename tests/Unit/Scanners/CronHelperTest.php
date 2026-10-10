<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Schedule\CronHelper;
use Lucasp\Loom\Support\Ast\CallSite;

/** Resolve the cron of the single `$s->{helper}(...)` call in $call. */
function cronOf(string $call): ?string
{
    $site = CallSite::of(parseExpr('$s->'.$call));
    expect($site)->not->toBeNull();

    $helper = CronHelper::tryFrom((string) $site->name());
    expect($helper)->not->toBeNull();

    return $helper->cron($site->args());
}

it('maps every helper to its cron', function (string $call, ?string $cron): void {
    expect(cronOf($call))->toBe($cron);
})->with([
    'cron' => ["cron('0 9 * * 1')", '0 9 * * 1'],
    'cron dynamic' => ['cron($c)', null],
    'everyMinute' => ['everyMinute()', '* * * * *'],
    'everyTwoMinutes' => ['everyTwoMinutes()', '*/2 * * * *'],
    'everyThreeMinutes' => ['everyThreeMinutes()', '*/3 * * * *'],
    'everyFourMinutes' => ['everyFourMinutes()', '*/4 * * * *'],
    'everyFiveMinutes' => ['everyFiveMinutes()', '*/5 * * * *'],
    'everyTenMinutes' => ['everyTenMinutes()', '*/10 * * * *'],
    'everyFifteenMinutes' => ['everyFifteenMinutes()', '*/15 * * * *'],
    'everyThirtyMinutes' => ['everyThirtyMinutes()', '0,30 * * * *'],
    'hourly' => ['hourly()', '0 * * * *'],
    'hourlyAt' => ['hourlyAt(17)', '17 * * * *'],
    'hourlyAt dynamic' => ['hourlyAt($m)', null],
    'everyOddHour' => ['everyOddHour()', '0 1-23/2 * * *'],
    'everyOddHour offset' => ['everyOddHour(15)', '15 1-23/2 * * *'],
    'everyTwoHours' => ['everyTwoHours(5)', '5 */2 * * *'],
    'everyThreeHours' => ['everyThreeHours()', '0 */3 * * *'],
    'everyFourHours' => ['everyFourHours()', '0 */4 * * *'],
    'everySixHours' => ['everySixHours()', '0 */6 * * *'],
    'daily' => ['daily()', '0 0 * * *'],
    'dailyAt' => ["dailyAt('13:30')", '30 13 * * *'],
    'dailyAt bad time' => ["dailyAt('noon')", null],
    'dailyAt dynamic' => ['dailyAt($t)', null],
    'twiceDaily' => ['twiceDaily()', '0 1,13 * * *'],
    'twiceDaily args' => ['twiceDaily(2, 14)', '0 2,14 * * *'],
    'twiceDailyAt' => ['twiceDailyAt(1, 13, 15)', '15 1,13 * * *'],
    'twiceDailyAt default minute' => ['twiceDailyAt(1, 13)', '0 1,13 * * *'],
    'twiceDailyAt dynamic' => ['twiceDailyAt(1, $h)', null],
    'weekly' => ['weekly()', '0 0 * * 0'],
    'weeklyOn day' => ["weeklyOn(1, '8:00')", '0 8 * * 1'],
    'weeklyOn default time' => ['weeklyOn(2)', '0 0 * * 2'],
    'weeklyOn days' => ["weeklyOn([1, 3, 5], '08:15')", '15 8 * * 1,3,5'],
    'weeklyOn dynamic' => ['weeklyOn($d)', null],
    'weeklyOn bad time' => ["weeklyOn(1, 'x')", null],
    'monthly' => ['monthly()', '0 0 1 * *'],
    'monthlyOn' => ["monthlyOn(4, '15:00')", '0 15 4 * *'],
    'monthlyOn defaults' => ['monthlyOn()', '0 0 1 * *'],
    'twiceMonthly' => ["twiceMonthly(1, 16, '13:00')", '0 13 1,16 * *'],
    'twiceMonthly defaults' => ['twiceMonthly()', '0 0 1,16 * *'],
    'daysOfMonth variadic' => ['daysOfMonth(1, 15)', '0 0 1,15 * *'],
    'daysOfMonth array' => ['daysOfMonth([2, 20])', '0 0 2,20 * *'],
    'daysOfMonth empty' => ['daysOfMonth()', null],
    'lastDayOfMonth' => ["lastDayOfMonth('15:00')", '0 15 L * *'],
    'lastDayOfMonth default' => ['lastDayOfMonth()', '0 0 L * *'],
    'quarterly' => ['quarterly()', '0 0 1 1-12/3 *'],
    'quarterlyOn' => ["quarterlyOn(4, '14:00')", '0 14 4 1-12/3 *'],
    'yearly' => ['yearly()', '0 0 1 1 *'],
    'yearlyOn' => ["yearlyOn(6, 1, '17:00')", '0 17 1 6 *'],
    'yearlyOn dynamic day' => ["yearlyOn(6, \$d, '17:00')", '0 17 1 6 *'],
    'yearlyOn defaults' => ['yearlyOn()', '0 0 1 1 *'],
]);
