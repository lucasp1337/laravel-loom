<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Dto\ScheduleFrequency;
use Lucasp\Loom\Index\FrequencyUnit;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Scanners\Visitors\ScheduleChainVisitor;
use Lucasp\Loom\Support\AppPath;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Fqcn;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;
use PhpParser\Node;

/**
 * Discovers entries declared in Laravel's task scheduler (Kernel, bootstrap/app.php,
 * routes/console.php and Schedule facade calls under app/).
 *
 * @internal
 */
final class ScheduleScanner implements Scanner
{
    use ScannerFilesystem;

    private const FREQUENCY_HELPERS = [
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes',
        'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'hourlyAt',
        'everyOddHour',
        'everyTwoHours', 'everyThreeHours', 'everyFourHours', 'everySixHours',
        'daily', 'dailyAt', 'twiceDaily', 'twiceDailyAt',
        'weekly', 'weeklyOn',
        'monthly', 'monthlyOn', 'twiceMonthly', 'daysOfMonth', 'lastDayOfMonth',
        'quarterly', 'quarterlyOn', 'yearly', 'yearlyOn',
        'cron',
    ];

    /** Sub-minute helpers can't be a 5-field cron; they emit a structured frequency in seconds. */
    private const SUB_MINUTE_SECONDS = [
        'everySecond' => 1, 'everyTwoSeconds' => 2, 'everyFiveSeconds' => 5,
        'everyTenSeconds' => 10, 'everyFifteenSeconds' => 15,
        'everyTwentySeconds' => 20, 'everyThirtySeconds' => 30,
    ];

    /** Day-of-week helpers configure runsOn constraints, not cron. */
    private const DAY_CONSTRAINTS = ['weekdays', 'weekends', 'sundays', 'mondays', 'tuesdays', 'wednesdays', 'thursdays', 'fridays', 'saturdays'];

    /**
     * Methods we know are NOT frequency setters. Anything else following a
     * frequency helper nulls the cron — an unknown method could be a future
     * Laravel helper or a user macro that clobbers the cron at runtime.
     */
    private const SAFE_MODIFIERS = [
        'timezone', 'withoutOverlapping', 'onOneServer', 'runInBackground',
        'weekdays', 'weekends',
        'sundays', 'mondays', 'tuesdays', 'wednesdays', 'thursdays', 'fridays', 'saturdays',
        'between', 'unlessBetween', 'when', 'skip', 'environments', 'days',
        'name', 'description', 'user', 'evenInMaintenanceMode',
        'ping', 'pingBefore', 'pingBeforeIf', 'thenPing', 'thenPingIf',
        'pingOnSuccess', 'pingOnFailure',
        'onSuccess', 'onFailure', 'then', 'before', 'after',
        'appendOutputTo', 'sendOutputTo', 'emailOutputTo', 'emailOutputOnFailure',
    ];

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null, ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->scope = $scope;
    }

    /**
     * @return array{scheduled_tasks: list<ScheduledEntry>}
     */
    public function scan(string $appRoot): array
    {
        /** @var array<string, ScheduledEntry> $entries keyed by file|line|kind|target */
        $entries = [];

        foreach ($this->discoverKernelForm($appRoot) as $entry) {
            $entries[$this->dedupeKey($entry)] = $entry;
        }
        foreach ($this->discoverBootstrapForm($appRoot) as $entry) {
            $entries[$this->dedupeKey($entry)] = $entry;
        }
        foreach ($this->discoverConsoleRoutesForm($appRoot) as $entry) {
            $key = $this->dedupeKey($entry);
            if (! isset($entries[$key])) {
                $entries[$key] = $entry;
            }
        }
        foreach ($this->discoverFacadeForm($appRoot) as $entry) {
            $key = $this->dedupeKey($entry);
            if (! isset($entries[$key])) {
                $entries[$key] = $entry;
            }
        }

        $result = array_values($entries);
        $result = array_values(collect($result)->sort(fn (ScheduledEntry $a, ScheduledEntry $b): int => [$a->file, $a->line] <=> [$b->file, $b->line])->all());

        return ['scheduled_tasks' => $result];
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverKernelForm(string $appRoot): array
    {
        $entries = [];

        foreach ($this->scope()->directories($appRoot) as $directory) {
            $file = $directory.DIRECTORY_SEPARATOR.'Console'.DIRECTORY_SEPARATOR.'Kernel.php';
            if (! is_file($file) || $this->scope()->isExcluded($appRoot, $file)) {
                continue;
            }

            // Fresh visitor per file: walk()===null bypasses beforeTraverse.
            $visitor = new ScheduleChainVisitor(ScheduleMode::KERNEL);
            if ($this->walker->walk($file, [$visitor]) === null) {
                continue;
            }

            foreach ($this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file)) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverBootstrapForm(string $appRoot): array
    {
        $file = AppPath::join($appRoot, 'bootstrap/app.php');
        if (! is_file($file) || $this->scope()->isExcluded($appRoot, $file)) {
            return [];
        }

        $visitor = new ScheduleChainVisitor(ScheduleMode::BOOTSTRAP);
        if ($this->walker->walk($file, [$visitor]) === null) {
            return [];
        }

        return $this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file));
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverConsoleRoutesForm(string $appRoot): array
    {
        $file = AppPath::join($appRoot, 'routes/console.php');
        if (! is_file($file) || $this->scope()->isExcluded($appRoot, $file)) {
            return [];
        }

        $visitor = new ScheduleChainVisitor(ScheduleMode::FACADE);
        if ($this->walker->walk($file, [$visitor]) === null) {
            return [];
        }

        return $this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file));
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverFacadeForm(string $appRoot): array
    {
        $entries = [];

        foreach ($this->scanFiles($appRoot) as $file) {
            // Fresh visitor per file: walk()===null bypasses beforeTraverse,
            // so reusing one would leak the previous file's entries.
            $visitor = new ScheduleChainVisitor(ScheduleMode::FACADE);
            if ($this->walker->walk($file->getPathname(), [$visitor]) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($this->translate($visitor->getEntries(), $relative) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  list<ScheduleChainEntry>  $rawEntries
     * @return list<ScheduledEntry>
     */
    private function translate(array $rawEntries, string $relativeFile): array
    {
        $out = [];
        foreach ($rawEntries as $raw) {
            $target = $this->resolveTarget($raw->kind, $raw->rootArgs);

            $arguments = $raw->kind === ScheduleKind::COMMAND
                ? $this->resolveCommandArguments($raw->rootArgs)
                : [];
            $queue = $raw->kind === ScheduleKind::JOB
                ? Literal::string($raw->rootArgs->valueAt(1))
                : null;
            $connection = $raw->kind === ScheduleKind::JOB
                ? Literal::string($raw->rootArgs->valueAt(2))
                : null;

            $cron = null;
            $frequency = null;
            $name = null;
            $timezone = null;
            $withoutOverlapping = false;
            $withoutOverlappingExpiresAt = null;
            $onOneServer = false;
            $runInBackground = false;
            $evenInMaintenanceMode = false;
            $constraints = [];
            $cronWasSet = false;

            // Index 0 is the root call; modifiers start at index 1.
            $chain = $raw->chain;
            for ($i = 1, $n = count($chain); $i < $n; $i++) {
                $method = $chain[$i]->method;
                $args = $chain[$i]->args;

                if (isset(self::SUB_MINUTE_SECONDS[$method])) {
                    $frequency = new ScheduleFrequency(FrequencyUnit::SECONDS, self::SUB_MINUTE_SECONDS[$method]);
                    $cron = null;            // sub-minute can't be a cron; last-wins
                    $cronWasSet = true;      // so a following safe modifier doesn't trip the unknown-method guard

                    continue;
                }

                if (collect(self::FREQUENCY_HELPERS)->containsStrict($method)) {
                    // Last-wins, including null when args are unresolvable.
                    $cron = $this->cronFromHelper($method, $args);
                    $frequency = null;       // a cron-based helper overrides any prior sub-minute frequency
                    $cronWasSet = true;

                    continue;
                }

                // Unknown method after a frequency helper: could be a future
                // helper or a Schedule::macro. Null cron and frequency to avoid lying.
                if ($cronWasSet && ! collect(self::SAFE_MODIFIERS)->containsStrict($method)) {
                    $cron = null;
                    $frequency = null;
                }

                if ($method === 'name') {
                    $label = Literal::string($args->valueAt(0));
                    if ($label !== null) {
                        $name = $label;
                    }

                    continue;
                }

                if ($method === 'timezone') {
                    $tz = Literal::string($args->valueAt(0));
                    if ($tz !== null) {
                        $timezone = $tz;
                    }

                    continue;
                }

                if ($method === 'withoutOverlapping') {
                    $withoutOverlapping = true;
                    $withoutOverlappingExpiresAt = Literal::int($args->valueAt(0));

                    continue;
                }

                if ($method === 'onOneServer') {
                    $onOneServer = true;

                    continue;
                }

                if ($method === 'runInBackground') {
                    $runInBackground = true;

                    continue;
                }

                if ($method === 'evenInMaintenanceMode') {
                    $evenInMaintenanceMode = true;

                    continue;
                }

                $constraint = $this->constraintFor($method, $args);
                if ($constraint !== null) {
                    $constraints[] = $constraint;
                }
            }

            $constraints = array_values(collect($constraints)->sort()->all());

            $out[] = new ScheduledEntry(
                kind: $raw->kind,
                name: $name,
                target: $target,
                arguments: $arguments,
                queue: $queue,
                connection: $connection,
                cron: $cron,
                frequency: $frequency,
                timezone: $timezone,
                withoutOverlapping: $withoutOverlapping,
                withoutOverlappingExpiresAt: $withoutOverlappingExpiresAt,
                onOneServer: $onOneServer,
                runInBackground: $runInBackground,
                evenInMaintenanceMode: $evenInMaintenanceMode,
                constraints: $constraints,
                file: $relativeFile,
                line: $raw->line,
            );
        }

        return $out;
    }

    private function resolveTarget(ScheduleKind $kind, Args $rootArgs): ?string
    {
        $value = $rootArgs->valueAt(0);
        if ($value === null) {
            return null;
        }

        if ($kind === ScheduleKind::COMMAND) {
            $string = Literal::string($value);
            if ($string !== null) {
                return $string;
            }
            $fqcn = ClassRef::fromInstanceOrConstant($value);

            return $fqcn;
        }

        if ($kind === ScheduleKind::JOB) {
            return ClassRef::fromInstanceOrConstant($value);
        }

        if ($kind === ScheduleKind::EXEC) {
            return Literal::string($value);
        }

        // closure: [Class::class, 'method'] tuple or 'App\\Cls@method' string.
        if ($value instanceof Node\Expr\Array_) {
            return $this->tupleCallableTarget($value);
        }

        $string = Literal::string($value);
        if ($string !== null) {
            return $this->atCallableToStatic($string);
        }

        return null;
    }

    /**
     * Resolves the `$parameters` array of a scheduled command into a flat
     * list. Plain items emit their literal value; keyed items emit "key=value".
     * Unresolvable items are skipped rather than fabricated.
     *
     * @return list<string>
     */
    private function resolveCommandArguments(Args $rootArgs): array
    {
        $array = $rootArgs->valueAt(1);
        if (! $array instanceof Node\Expr\Array_) {
            return [];
        }

        $out = [];
        foreach ($array->items as $item) {
            $value = $this->scalarToString($item->value);
            if ($value === null) {
                continue;
            }

            if ($item->key === null) {
                $out[] = $value;

                continue;
            }

            $key = $this->scalarToString($item->key);
            if ($key !== null) {
                $out[] = $key.'='.$value;
            }
        }

        return $out;
    }

    /** Stringify a scalar literal node (string, int, or bool) or null if unresolvable. */
    private function scalarToString(Node\Expr $node): ?string
    {
        $string = Literal::string($node);
        if ($string !== null) {
            return $string;
        }

        $int = Literal::int($node);
        if ($int !== null) {
            return (string) $int;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $name = strtolower($node->name->toString());
            if ($name === 'true' || $name === 'false') {
                return $name;
            }
        }

        return null;
    }

    private function tupleCallableTarget(Node\Expr\Array_ $array): ?string
    {
        if (count($array->items) !== 2) {
            return null;
        }
        $classItem = $array->items[0];
        $methodItem = $array->items[1];

        $fqcn = ClassRef::fromInstanceOrConstant($classItem->value);
        $method = null;
        if ($methodItem->value instanceof Node\Scalar\String_) {
            $method = $methodItem->value->value;
        }

        if ($fqcn === null || $method === null) {
            return null;
        }

        return $fqcn.'::'.$method;
    }

    private function atCallableToStatic(string $value): string
    {
        $parts = Fqcn::splitAtMember($value);

        return $parts === null ? $value : $parts[0].'::'.$parts[1];
    }

    /**
     * @return list<int>|null
     */
    private function scalarIntArray(?Node\Expr $node): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }
        $values = [];
        foreach ($node->items as $item) {
            $int = Literal::int($item->value);
            if ($int === null) {
                return null;
            }
            $values[] = $int;
        }
        if ($values === []) {
            return null;
        }

        return $values;
    }

    /**
     * Mirrors `Illuminate\Console\Scheduling\ManagesFrequencies`. Returns
     * null when an arg can't be resolved statically.
     */
    private function cronFromHelper(string $method, Args $args): ?string
    {
        switch ($method) {
            case 'cron':
                return Literal::string($args->valueAt(0));

            case 'everyMinute':
                return '* * * * *';
            case 'everyTwoMinutes':
                return '*/2 * * * *';
            case 'everyThreeMinutes':
                return '*/3 * * * *';
            case 'everyFourMinutes':
                return '*/4 * * * *';
            case 'everyFiveMinutes':
                return '*/5 * * * *';
            case 'everyTenMinutes':
                return '*/10 * * * *';
            case 'everyFifteenMinutes':
                return '*/15 * * * *';
            case 'everyThirtyMinutes':
                return '0,30 * * * *';

            case 'hourly':
                return '0 * * * *';
            case 'hourlyAt':
                $minute = Literal::int($args->valueAt(0));

                return $minute === null ? null : $minute.' * * * *';

            case 'everyOddHour':
                return (Literal::int($args->valueAt(0)) ?? 0).' 1-23/2 * * *';

            case 'everyTwoHours':
                return (Literal::int($args->valueAt(0)) ?? 0).' */2 * * *';
            case 'everyThreeHours':
                return (Literal::int($args->valueAt(0)) ?? 0).' */3 * * *';
            case 'everyFourHours':
                return (Literal::int($args->valueAt(0)) ?? 0).' */4 * * *';
            case 'everySixHours':
                return (Literal::int($args->valueAt(0)) ?? 0).' */6 * * *';

            case 'daily':
                return '0 0 * * *';
            case 'dailyAt':
                $time = Literal::string($args->valueAt(0));
                if ($time === null) {
                    return null;
                }
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * *';

            case 'twiceDaily':
                $first = Literal::int($args->valueAt(0)) ?? 1;
                $second = Literal::int($args->valueAt(1)) ?? 13;

                return '0 '.$first.','.$second.' * * *';

            case 'twiceDailyAt':
                $first = Literal::int($args->valueAt(0));
                $second = Literal::int($args->valueAt(1));
                $minute = Literal::int($args->valueAt(2)) ?? 0;
                if ($first === null || $second === null) {
                    return null;
                }

                return $minute.' '.$first.','.$second.' * * *';

            case 'weekly':
                return '0 0 * * 0';
            case 'weeklyOn':
                $time = Literal::string($args->valueAt(1)) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }
                $day = Literal::int($args->valueAt(0));
                if ($day !== null) {
                    return $minute.' '.$hour.' * * '.$day;
                }
                // weeklyOn([1, 3, 5], '08:00') form.
                $days = $this->scalarIntArray($args->valueAt(0));
                if ($days === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * '.Arr::join($days, ',');

            case 'monthly':
                return '0 0 1 * *';
            case 'monthlyOn':
                $day = Literal::int($args->valueAt(0)) ?? 1;
                $time = Literal::string($args->valueAt(1)) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$day.' * *';

            case 'twiceMonthly':
                $first = Literal::int($args->valueAt(0)) ?? 1;
                $second = Literal::int($args->valueAt(1)) ?? 16;
                $time = Literal::string($args->valueAt(2)) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$first.','.$second.' * *';

            case 'daysOfMonth':
                // Accepts variadic ints (daysOfMonth(1, 15)) or a single array
                // (daysOfMonth([1, 15])). Laravel runs these at 00:00.
                $days = $this->collectDayArgs($args);

                return $days === [] ? null : '0 0 '.Arr::join($days, ',').' * *';

            case 'lastDayOfMonth':
                $time = Literal::string($args->valueAt(0)) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                // "L" matches Laravel's runtime token for last-day-of-month.
                return $minute.' '.$hour.' L * *';

            case 'quarterly':
                return '0 0 1 1-12/3 *';
            case 'quarterlyOn':
                $day = Literal::int($args->valueAt(0)) ?? 1;
                $time = Literal::string($args->valueAt(1)) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$day.' 1-12/3 *';
            case 'yearly':
                return '0 0 1 1 *';
            case 'yearlyOn':
                $month = Literal::int($args->valueAt(0)) ?? 1;
                $day = $args->valueAt(1);
                $time = Literal::string($args->valueAt(2)) ?? '0:00';
                $dayInt = Literal::int($day);
                if ($dayInt === null) {
                    $dayInt = 1;
                }
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$dayInt.' '.$month.' *';
        }

        return null;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function splitTime(string $time): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function constraintFor(string $method, Args $args): ?string
    {
        if (collect(self::DAY_CONSTRAINTS)->containsStrict($method)) {
            return $method;
        }

        if ($method === 'between' || $method === 'unlessBetween') {
            $a = Literal::string($args->valueAt(0));
            $b = Literal::string($args->valueAt(1));
            if ($a !== null && $b !== null) {
                return $method.'('.$a.','.$b.')';
            }

            return $method.'(closure)';
        }

        if ($method === 'when' || $method === 'skip') {
            return $method.'(closure)';
        }

        if ($method === 'environments') {
            $values = [];
            foreach ($args->values() as $value) {
                $s = Literal::string($value);
                if ($s !== null) {
                    $values[] = $s;

                    continue;
                }
                if ($value instanceof Node\Expr\Array_) {
                    foreach ($value->items as $item) {
                        if ($item->value instanceof Node\Scalar\String_) {
                            $values[] = $item->value->value;
                        }
                    }
                }
            }

            return $values === [] ? 'environments(closure)' : 'environments('.Arr::join($values, ',').')';
        }

        if ($method === 'days') {
            $values = $this->collectDayArgs($args);

            // "days(?)" signals an unresolved arg without fabricating a value.
            return $values === [] ? 'days(?)' : 'days('.Arr::join($values, ',').')';
        }

        return null;
    }

    /**
     * Collects statically-resolvable day integers from a variadic int list
     * (days(0, 3)) or a single array argument (days([0, 3])).
     *
     * @return list<int>
     */
    private function collectDayArgs(Args $args): array
    {
        $values = [];
        foreach ($args->values() as $value) {
            $int = Literal::int($value);
            if ($int !== null) {
                $values[] = $int;

                continue;
            }
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    $itemInt = Literal::int($item->value);
                    if ($itemInt !== null) {
                        $values[] = $itemInt;
                    }
                }
            }
        }

        return $values;
    }

    private function dedupeKey(ScheduledEntry $entry): string
    {
        return $entry->file.'|'.$entry->line.'|'.$entry->kind->value.'|'.($entry->target ?? '');
    }
}
