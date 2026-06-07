<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Scanners\Visitors\ScheduleChainVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScannerFilesystem;
use PhpParser\Node;

/**
 * Discovers entries declared in Laravel's task scheduler.
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
        'monthly', 'monthlyOn', 'twiceMonthly', 'lastDayOfMonth',
        'quarterly', 'quarterlyOn', 'yearly', 'yearlyOn',
        'cron',
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

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
    }

    /**
     * @return array{scheduled: list<ScheduledEntry>}
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
        foreach ($this->discoverFacadeForm($appRoot) as $entry) {
            $key = $this->dedupeKey($entry);
            if (! isset($entries[$key])) {
                $entries[$key] = $entry;
            }
        }

        $result = array_values($entries);
        usort($result, fn (ScheduledEntry $a, ScheduledEntry $b): int => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return ['scheduled' => $result];
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverKernelForm(string $appRoot): array
    {
        $file = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Console'.DIRECTORY_SEPARATOR.'Kernel.php';
        if (! is_file($file)) {
            return [];
        }

        $visitor = new ScheduleChainVisitor(ScheduleMode::KERNEL);
        if ($this->walker->walk($file, [$visitor]) === null) {
            return [];
        }

        return $this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file));
    }

    /**
     * @return list<ScheduledEntry>
     */
    private function discoverBootstrapForm(string $appRoot): array
    {
        $file = $appRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (! is_file($file)) {
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
    private function discoverFacadeForm(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $entries = [];

        foreach ($this->iteratePhpFiles($appDir) as $file) {
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
                ? AstHelpers::scalarString($raw->rootArgs[1] ?? null)
                : null;
            $connection = $raw->kind === ScheduleKind::JOB
                ? AstHelpers::scalarString($raw->rootArgs[2] ?? null)
                : null;

            $cron = null;
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

                if (in_array($method, self::FREQUENCY_HELPERS, true)) {
                    // Last-wins, including null when args are unresolvable.
                    $cron = $this->cronFromHelper($method, $args);
                    $cronWasSet = true;

                    continue;
                }

                // Unknown method after a frequency helper: could be a future
                // helper or a Schedule::macro. Null the cron to avoid lying.
                if ($cronWasSet && ! in_array($method, self::SAFE_MODIFIERS, true)) {
                    $cron = null;
                }

                if ($method === 'name') {
                    $label = AstHelpers::scalarString($args[0] ?? null);
                    if ($label !== null) {
                        $name = $label;
                    }

                    continue;
                }

                if ($method === 'timezone') {
                    $tz = AstHelpers::scalarString($args[0] ?? null);
                    if ($tz !== null) {
                        $timezone = $tz;
                    }

                    continue;
                }

                if ($method === 'withoutOverlapping') {
                    $withoutOverlapping = true;
                    $withoutOverlappingExpiresAt = AstHelpers::scalarInt($args[0] ?? null);

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

            sort($constraints);

            $out[] = new ScheduledEntry(
                kind: $raw->kind,
                name: $name,
                target: $target,
                arguments: $arguments,
                queue: $queue,
                connection: $connection,
                cron: $cron,
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

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $rootArgs
     */
    private function resolveTarget(ScheduleKind $kind, array $rootArgs): ?string
    {
        $first = $rootArgs[0] ?? null;
        if (! $first instanceof Node\Arg) {
            return null;
        }

        $value = $first->value;

        if ($kind === ScheduleKind::COMMAND) {
            $string = AstHelpers::scalarString($first);
            if ($string !== null) {
                return $string;
            }
            $fqcn = AstHelpers::resolveStaticClass($value);

            return $fqcn;
        }

        if ($kind === ScheduleKind::JOB) {
            return AstHelpers::resolveStaticClass($value);
        }

        if ($kind === ScheduleKind::EXEC) {
            return AstHelpers::scalarString($first);
        }

        // closure: [Class::class, 'method'] tuple or 'App\\Cls@method' string.
        if ($value instanceof Node\Expr\Array_) {
            return $this->tupleCallableTarget($value);
        }

        $string = AstHelpers::scalarString($first);
        if ($string !== null) {
            return $this->normaliseAtCallable($string);
        }

        return null;
    }

    /**
     * Resolves the `$parameters` array of a scheduled command into a flat
     * list. Plain items emit their literal value; keyed items emit "key=value".
     * Unresolvable items are skipped rather than fabricated.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $rootArgs
     * @return list<string>
     */
    private function resolveCommandArguments(array $rootArgs): array
    {
        $arg = $rootArgs[1] ?? null;
        if (! $arg instanceof Node\Arg || ! $arg->value instanceof Node\Expr\Array_) {
            return [];
        }

        $out = [];
        foreach ($arg->value->items as $item) {
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
        $string = AstHelpers::scalarString($node);
        if ($string !== null) {
            return $string;
        }

        $int = AstHelpers::scalarInt($node);
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

        $fqcn = AstHelpers::resolveStaticClass($classItem->value);
        $method = null;
        if ($methodItem->value instanceof Node\Scalar\String_) {
            $method = $methodItem->value->value;
        }

        if ($fqcn === null || $method === null) {
            return null;
        }

        return $fqcn.'::'.$method;
    }

    private function normaliseAtCallable(string $value): string
    {
        if (str_contains($value, '@')) {
            [$class, $method] = explode('@', $value, 2);

            return $class.'::'.$method;
        }

        return $value;
    }

    /**
     * @return list<int>|null
     */
    private function scalarIntArray(?Node $node): ?array
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }
        $values = [];
        foreach ($node->items as $item) {
            $int = AstHelpers::scalarInt($item->value);
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
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function cronFromHelper(string $method, array $args): ?string
    {
        switch ($method) {
            case 'cron':
                return AstHelpers::scalarString($args[0] ?? null);

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
                $minute = AstHelpers::scalarInt($args[0] ?? null);

                return $minute === null ? null : $minute.' * * * *';

            case 'everyOddHour':
                return (AstHelpers::scalarInt($args[0] ?? null) ?? 0).' 1-23/2 * * *';

            case 'everyTwoHours':
                return (AstHelpers::scalarInt($args[0] ?? null) ?? 0).' */2 * * *';
            case 'everyThreeHours':
                return (AstHelpers::scalarInt($args[0] ?? null) ?? 0).' */3 * * *';
            case 'everyFourHours':
                return (AstHelpers::scalarInt($args[0] ?? null) ?? 0).' */4 * * *';
            case 'everySixHours':
                return (AstHelpers::scalarInt($args[0] ?? null) ?? 0).' */6 * * *';

            case 'daily':
                return '0 0 * * *';
            case 'dailyAt':
                $time = AstHelpers::scalarString($args[0] ?? null);
                if ($time === null) {
                    return null;
                }
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * *';

            case 'twiceDaily':
                $first = AstHelpers::scalarInt($args[0] ?? null) ?? 1;
                $second = AstHelpers::scalarInt($args[1] ?? null) ?? 13;

                return '0 '.$first.','.$second.' * * *';

            case 'twiceDailyAt':
                $first = AstHelpers::scalarInt($args[0] ?? null);
                $second = AstHelpers::scalarInt($args[1] ?? null);
                $minute = AstHelpers::scalarInt($args[2] ?? null) ?? 0;
                if ($first === null || $second === null) {
                    return null;
                }

                return $minute.' '.$first.','.$second.' * * *';

            case 'weekly':
                return '0 0 * * 0';
            case 'weeklyOn':
                $time = AstHelpers::scalarString($args[1] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }
                $day = AstHelpers::scalarInt($args[0] ?? null);
                if ($day !== null) {
                    return $minute.' '.$hour.' * * '.$day;
                }
                // weeklyOn([1, 3, 5], '08:00') form.
                $days = $this->scalarIntArray($args[0] ?? null);
                if ($days === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * '.implode(',', $days);

            case 'monthly':
                return '0 0 1 * *';
            case 'monthlyOn':
                $day = AstHelpers::scalarInt($args[0] ?? null) ?? 1;
                $time = AstHelpers::scalarString($args[1] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$day.' * *';

            case 'twiceMonthly':
                $first = AstHelpers::scalarInt($args[0] ?? null) ?? 1;
                $second = AstHelpers::scalarInt($args[1] ?? null) ?? 16;
                $time = AstHelpers::scalarString($args[2] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$first.','.$second.' * *';

            case 'lastDayOfMonth':
                $time = AstHelpers::scalarString($args[0] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                // "L" matches Laravel's runtime token for last-day-of-month.
                return $minute.' '.$hour.' L * *';

            case 'quarterly':
                return '0 0 1 1-12/3 *';
            case 'quarterlyOn':
                $day = AstHelpers::scalarInt($args[0] ?? null) ?? 1;
                $time = AstHelpers::scalarString($args[1] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$day.' 1-12/3 *';
            case 'yearly':
                return '0 0 1 1 *';
            case 'yearlyOn':
                $month = AstHelpers::scalarInt($args[0] ?? null) ?? 1;
                $day = $args[1] ?? null;
                $time = AstHelpers::scalarString($args[2] ?? null) ?? '0:00';
                $dayInt = AstHelpers::scalarInt($day);
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

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function constraintFor(string $method, array $args): ?string
    {
        if (in_array($method, self::DAY_CONSTRAINTS, true)) {
            return $method;
        }

        if ($method === 'between' || $method === 'unlessBetween') {
            $a = AstHelpers::scalarString($args[0] ?? null);
            $b = AstHelpers::scalarString($args[1] ?? null);
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
            foreach ($args as $arg) {
                if (! $arg instanceof Node\Arg) {
                    continue;
                }
                $s = AstHelpers::scalarString($arg);
                if ($s !== null) {
                    $values[] = $s;

                    continue;
                }
                if ($arg->value instanceof Node\Expr\Array_) {
                    foreach ($arg->value->items as $item) {
                        if ($item->value instanceof Node\Scalar\String_) {
                            $values[] = $item->value->value;
                        }
                    }
                }
            }

            return $values === [] ? 'environments(closure)' : 'environments('.implode(',', $values).')';
        }

        if ($method === 'days') {
            // Accepts variadic ints (days(0, 3)) or a single array (days([0, 3])).
            $values = [];
            foreach ($args as $arg) {
                if (! $arg instanceof Node\Arg) {
                    continue;
                }
                $int = AstHelpers::scalarInt($arg);
                if ($int !== null) {
                    $values[] = $int;

                    continue;
                }
                if ($arg->value instanceof Node\Expr\Array_) {
                    foreach ($arg->value->items as $item) {
                        $itemInt = AstHelpers::scalarInt($item->value);
                        if ($itemInt !== null) {
                            $values[] = $itemInt;
                        }
                    }
                }
            }

            // "days(?)" signals an unresolved arg without fabricating a value.
            return $values === [] ? 'days(?)' : 'days('.implode(',', $values).')';
        }

        return null;
    }

    private function dedupeKey(ScheduledEntry $entry): string
    {
        return $entry->file.'|'.$entry->line.'|'.$entry->kind->value.'|'.($entry->target ?? '');
    }
}
