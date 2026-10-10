<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

use Illuminate\Support\Arr;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Literal;

/**
 * The scheduler frequency helpers that translate to a 5-field cron
 * expression, mirroring `Illuminate\Console\Scheduling\ManagesFrequencies`.
 * The case value is the method name as written in a `->daily()` chain.
 *
 * @internal
 */
enum CronHelper: string
{
    case CRON = 'cron';
    case EVERY_MINUTE = 'everyMinute';
    case EVERY_TWO_MINUTES = 'everyTwoMinutes';
    case EVERY_THREE_MINUTES = 'everyThreeMinutes';
    case EVERY_FOUR_MINUTES = 'everyFourMinutes';
    case EVERY_FIVE_MINUTES = 'everyFiveMinutes';
    case EVERY_TEN_MINUTES = 'everyTenMinutes';
    case EVERY_FIFTEEN_MINUTES = 'everyFifteenMinutes';
    case EVERY_THIRTY_MINUTES = 'everyThirtyMinutes';
    case HOURLY = 'hourly';
    case HOURLY_AT = 'hourlyAt';
    case EVERY_ODD_HOUR = 'everyOddHour';
    case EVERY_TWO_HOURS = 'everyTwoHours';
    case EVERY_THREE_HOURS = 'everyThreeHours';
    case EVERY_FOUR_HOURS = 'everyFourHours';
    case EVERY_SIX_HOURS = 'everySixHours';
    case DAILY = 'daily';
    case DAILY_AT = 'dailyAt';
    case TWICE_DAILY = 'twiceDaily';
    case TWICE_DAILY_AT = 'twiceDailyAt';
    case WEEKLY = 'weekly';
    case WEEKLY_ON = 'weeklyOn';
    case MONTHLY = 'monthly';
    case MONTHLY_ON = 'monthlyOn';
    case TWICE_MONTHLY = 'twiceMonthly';
    case DAYS_OF_MONTH = 'daysOfMonth';
    case LAST_DAY_OF_MONTH = 'lastDayOfMonth';
    case QUARTERLY = 'quarterly';
    case QUARTERLY_ON = 'quarterlyOn';
    case YEARLY = 'yearly';
    case YEARLY_ON = 'yearlyOn';

    /**
     * The cron expression this helper sets for the call's arguments; null when
     * an argument cannot be resolved statically.
     */
    public function cron(Args $args): ?string
    {
        return match ($this) {
            // `->cron('0 9 * * 1')`: the expression itself
            self::CRON => Literal::string($args->valueAt(0)),

            // argument-free minute cadences
            self::EVERY_MINUTE => '* * * * *',
            self::EVERY_TWO_MINUTES => '*/2 * * * *',
            self::EVERY_THREE_MINUTES => '*/3 * * * *',
            self::EVERY_FOUR_MINUTES => '*/4 * * * *',
            self::EVERY_FIVE_MINUTES => '*/5 * * * *',
            self::EVERY_TEN_MINUTES => '*/10 * * * *',
            self::EVERY_FIFTEEN_MINUTES => '*/15 * * * *',
            self::EVERY_THIRTY_MINUTES => '0,30 * * * *',

            // `->hourly()`
            self::HOURLY => '0 * * * *',
            // `->hourlyAt(17)`: the minute must be an int literal
            self::HOURLY_AT => $this->withMinute($args),

            // `->everyOddHour()` / `->everyTwoHours(15)`: optional minute offset, default 0
            self::EVERY_ODD_HOUR => $this->minuteOffset($args).' 1-23/2 * * *',
            self::EVERY_TWO_HOURS => $this->minuteOffset($args).' */2 * * *',
            self::EVERY_THREE_HOURS => $this->minuteOffset($args).' */3 * * *',
            self::EVERY_FOUR_HOURS => $this->minuteOffset($args).' */4 * * *',
            self::EVERY_SIX_HOURS => $this->minuteOffset($args).' */6 * * *',

            // `->daily()`
            self::DAILY => '0 0 * * *',
            // `->dailyAt('13:30')`: an `H:i` string literal
            self::DAILY_AT => $this->atTime(Literal::string($args->valueAt(0)), '* * *'),
            // `->twiceDaily(1, 13)`: two hours, defaults 1 and 13
            self::TWICE_DAILY => '0 '.(Literal::int($args->valueAt(0)) ?? 1).','.(Literal::int($args->valueAt(1)) ?? 13).' * * *',
            // `->twiceDailyAt(1, 13, 15)`: both hours must be int literals, minute defaults 0
            self::TWICE_DAILY_AT => $this->twiceDailyAt($args),

            // `->weekly()`
            self::WEEKLY => '0 0 * * 0',
            // `->weeklyOn(1, '8:00')` or `->weeklyOn([1, 3, 5], '8:00')`
            self::WEEKLY_ON => $this->weeklyOn($args),

            // `->monthly()`
            self::MONTHLY => '0 0 1 * *',
            // `->monthlyOn(4, '15:00')`: day defaults 1, time defaults 0:00
            self::MONTHLY_ON => $this->atTime(
                Literal::string($args->valueAt(1)) ?? '0:00',
                (Literal::int($args->valueAt(0)) ?? 1).' * *',
            ),
            // `->twiceMonthly(1, 16, '13:00')`: days default 1 and 16, time defaults 0:00
            self::TWICE_MONTHLY => $this->atTime(
                Literal::string($args->valueAt(2)) ?? '0:00',
                (Literal::int($args->valueAt(0)) ?? 1).','.(Literal::int($args->valueAt(1)) ?? 16).' * *',
            ),
            // `->daysOfMonth(1, 15)` or `->daysOfMonth([1, 15])`: runs at 00:00
            self::DAYS_OF_MONTH => $this->daysOfMonth($args),
            // `->lastDayOfMonth('15:00')`: `L` is Laravel's last-day token
            self::LAST_DAY_OF_MONTH => $this->atTime(Literal::string($args->valueAt(0)) ?? '0:00', 'L * *'),

            // `->quarterly()`
            self::QUARTERLY => '0 0 1 1-12/3 *',
            // `->quarterlyOn(4, '14:00')`: day defaults 1, time defaults 0:00
            self::QUARTERLY_ON => $this->atTime(
                Literal::string($args->valueAt(1)) ?? '0:00',
                (Literal::int($args->valueAt(0)) ?? 1).' 1-12/3 *',
            ),
            // `->yearly()`
            self::YEARLY => '0 0 1 1 *',
            // `->yearlyOn(6, 1, '17:00')`: month defaults 1, day defaults 1 (also when non-literal), time defaults 0:00
            self::YEARLY_ON => $this->atTime(
                Literal::string($args->valueAt(2)) ?? '0:00',
                (Literal::int($args->valueAt(1)) ?? 1).' '.(Literal::int($args->valueAt(0)) ?? 1).' *',
            ),
        };
    }

    private function withMinute(Args $args): ?string
    {
        $minute = Literal::int($args->valueAt(0));

        return $minute === null ? null : $minute.' * * * *';
    }

    /** The optional minute-offset argument of the `everyNHours` helpers, default 0. */
    private function minuteOffset(Args $args): int
    {
        return Literal::int($args->valueAt(0)) ?? 0;
    }

    /**
     * `<minute> <hour> <rest>` for an `H:i` time; null when the time is absent
     * or not in that shape.
     */
    private function atTime(?string $time, string $rest): ?string
    {
        if ($time === null) {
            return null;
        }

        [$hour, $minute] = ScheduleArgs::splitTime($time);

        return $hour === null ? null : $minute.' '.$hour.' '.$rest;
    }

    private function twiceDailyAt(Args $args): ?string
    {
        $first = Literal::int($args->valueAt(0));
        $second = Literal::int($args->valueAt(1));
        if ($first === null || $second === null) {
            return null;
        }

        return (Literal::int($args->valueAt(2)) ?? 0).' '.$first.','.$second.' * * *';
    }

    private function weeklyOn(Args $args): ?string
    {
        $time = Literal::string($args->valueAt(1)) ?? '0:00';
        $day = Literal::int($args->valueAt(0));

        // `weeklyOn(1, ...)`: a single day
        if ($day !== null) {
            return $this->atTime($time, '* * '.$day);
        }

        // `weeklyOn([1, 3, 5], ...)`: a list of days
        $days = ScheduleArgs::intArray($args->valueAt(0));

        return $days === null ? null : $this->atTime($time, '* * '.Arr::join($days, ','));
    }

    private function daysOfMonth(Args $args): ?string
    {
        $days = ScheduleArgs::dayArgs($args);

        return $days === [] ? null : '0 0 '.Arr::join($days, ',').' * *';
    }
}
