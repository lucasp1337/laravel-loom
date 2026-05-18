<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use FilesystemIterator;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\ScheduleChainVisitor;
use Lucasp\Loom\Support\AstWalker;
use PhpParser\Node;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Discovers entries declared in Laravel's task scheduler.
 *
 * See docs/scanners/schedule.md and docs/adr/0002-schedule-scanner.md.
 */
final class ScheduleScanner implements Scanner
{
    private const FREQUENCY_HELPERS = [
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes',
        'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'hourlyAt',
        'everyTwoHours', 'everyThreeHours', 'everyFourHours', 'everySixHours',
        'daily', 'dailyAt', 'twiceDaily', 'twiceDailyAt',
        'weekly', 'weeklyOn',
        'monthly', 'monthlyOn', 'twiceMonthly', 'lastDayOfMonth',
        'quarterly', 'yearly', 'yearlyOn',
        'cron',
    ];

    /**
     * Day-of-week constraint helpers. These also exist as frequency helpers
     * in Laravel (`->mondays()` configures `runsOn` constraint, not a cron),
     * so we treat them as constraints only.
     */
    private const DAY_CONSTRAINTS = ['weekdays', 'weekends', 'sundays', 'mondays', 'tuesdays', 'wednesdays', 'thursdays', 'fridays', 'saturdays'];

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function scan(string $appRoot): array
    {
        /** @var array<string, array<string, mixed>> $entries keyed by file|line|kind|target */
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
        usort($result, function (array $a, array $b): int {
            $af = is_string($a['file'] ?? null) ? $a['file'] : '';
            $bf = is_string($b['file'] ?? null) ? $b['file'] : '';
            $al = is_int($a['line'] ?? null) ? $a['line'] : 0;
            $bl = is_int($b['line'] ?? null) ? $b['line'] : 0;

            return [$af, $al] <=> [$bf, $bl];
        });

        return ['scheduled' => $result];
    }

    /**
     * Discover entries from `app/Console/Kernel.php`'s `schedule()` method.
     *
     * @return list<array<string, mixed>>
     */
    private function discoverKernelForm(string $appRoot): array
    {
        $file = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Console'.DIRECTORY_SEPARATOR.'Kernel.php';
        if (! is_file($file)) {
            return [];
        }

        $visitor = new ScheduleChainVisitor;
        $this->walker->walk($file, [$visitor]);

        return $this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file));
    }

    /**
     * Discover entries from `bootstrap/app.php`'s `withSchedule(...)` closure.
     *
     * The closure is walked by the same ScheduleChainVisitor, which is happy
     * to accept any variable receiver for the chain root.
     *
     * @return list<array<string, mixed>>
     */
    private function discoverBootstrapForm(string $appRoot): array
    {
        $file = $appRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (! is_file($file)) {
            return [];
        }

        $visitor = new ScheduleChainVisitor;
        $this->walker->walk($file, [$visitor]);

        return $this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file));
    }

    /**
     * Discover Schedule-facade-rooted chains anywhere under `app/`.
     *
     * @return list<array<string, mixed>>
     */
    private function discoverFacadeForm(string $appRoot): array
    {
        $appDir = $appRoot.DIRECTORY_SEPARATOR.'app';
        if (! is_dir($appDir)) {
            return [];
        }

        $entries = [];
        // Walking the whole app/ tree means we cannot trust an arbitrary
        // Variable receiver — only chains explicitly rooted at the Schedule
        // facade count. Otherwise any `$builder->command(...)->daily()` DSL
        // would be falsely emitted.
        $visitor = new ScheduleChainVisitor(requireFacadeRoot: true);

        foreach ($this->iteratePhpFiles($appDir) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($this->translate($visitor->getEntries(), $relative) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Translate raw visitor entries into schema-shaped entries.
     *
     * @param  array<int, array{kind: 'command'|'job'|'closure'|'exec', root_method: string, root_args: array<int, Node\Arg|Node\VariadicPlaceholder>, chain: list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>}>, line: int}>  $rawEntries
     * @return list<array<string, mixed>>
     */
    private function translate(array $rawEntries, string $relativeFile): array
    {
        $out = [];
        foreach ($rawEntries as $raw) {
            $target = $this->resolveTarget($raw['kind'], $raw['root_args']);

            $cron = null;
            $timezone = null;
            $withoutOverlapping = false;
            $onOneServer = false;
            $runInBackground = false;
            $constraints = [];

            // First link is the root call; modifiers start at index 1.
            $chain = $raw['chain'];
            for ($i = 1, $n = count($chain); $i < $n; $i++) {
                $link = $chain[$i];
                $method = $link['method'];
                $args = $link['args'];

                if (in_array($method, self::FREQUENCY_HELPERS, true)) {
                    // Last-wins: a recognised helper with an unresolvable
                    // arg (e.g. `dailyAt($time)`) clobbers any earlier value
                    // with null, mirroring Laravel's runtime "last cron
                    // expression set" semantics.
                    $cron = $this->cronFromHelper($method, $args);

                    continue;
                }

                if ($method === 'timezone') {
                    $tz = $this->scalarString($args[0] ?? null);
                    if ($tz !== null) {
                        $timezone = $tz;
                    }

                    continue;
                }

                if ($method === 'withoutOverlapping') {
                    $withoutOverlapping = true;

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

                // Constraint-shaped helpers.
                $constraint = $this->constraintFor($method, $args);
                if ($constraint !== null) {
                    $constraints[] = $constraint;
                }
            }

            sort($constraints);

            $out[] = [
                'kind' => $raw['kind'],
                'target' => $target,
                'cron' => $cron,
                'timezone' => $timezone,
                'without_overlapping' => $withoutOverlapping,
                'on_one_server' => $onOneServer,
                'run_in_background' => $runInBackground,
                'constraints' => $constraints,
                'file' => $relativeFile,
                'line' => $raw['line'],
            ];
        }

        return $out;
    }

    /**
     * Resolve the entry's target string given the root method and its args.
     *
     * @param  'command'|'job'|'closure'|'exec'  $kind
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $rootArgs
     */
    private function resolveTarget(string $kind, array $rootArgs): ?string
    {
        $first = $rootArgs[0] ?? null;
        if (! $first instanceof Node\Arg) {
            return null;
        }

        $value = $first->value;

        if ($kind === 'command') {
            $string = $this->scalarString($first);
            if ($string !== null) {
                return $string;
            }
            $fqcn = $this->resolveStaticClass($value);

            return $fqcn;
        }

        if ($kind === 'job') {
            return $this->resolveStaticClass($value);
        }

        if ($kind === 'exec') {
            return $this->scalarString($first);
        }

        // closure: support callable tuple [Class::class, 'method']
        // and Laravel callable strings 'App\\Cls@method'.
        if ($value instanceof Node\Expr\Array_) {
            return $this->tupleCallableTarget($value);
        }

        $string = $this->scalarString($first);
        if ($string !== null) {
            return $this->normaliseAtCallable($string);
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

        $fqcn = $this->resolveStaticClass($classItem->value);
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

    private function resolveStaticClass(?Node $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class'
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    /**
     * Read a scalar string from an Arg whose value is a String_ literal.
     */
    private function scalarString(?Node $node): ?string
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }

    /**
     * Read an integer scalar from an Arg.
     */
    private function scalarInt(?Node $node): ?int
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        return null;
    }

    /**
     * Convert a recognised frequency helper + args into a five-field cron
     * expression. Returns null if the helper requires an arg we can't
     * resolve statically.
     *
     * Cron output mirrors Laravel's `Illuminate\Console\Scheduling\ManagesFrequencies`.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function cronFromHelper(string $method, array $args): ?string
    {
        switch ($method) {
            case 'cron':
                return $this->scalarString($args[0] ?? null);

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
                $minute = $this->scalarInt($args[0] ?? null);

                return $minute === null ? null : $minute.' * * * *';

            case 'everyTwoHours':
                return '0 */2 * * *';
            case 'everyThreeHours':
                return '0 */3 * * *';
            case 'everyFourHours':
                return '0 */4 * * *';
            case 'everySixHours':
                return '0 */6 * * *';

            case 'daily':
                return '0 0 * * *';
            case 'dailyAt':
                $time = $this->scalarString($args[0] ?? null);
                if ($time === null) {
                    return null;
                }
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * *';

            case 'twiceDaily':
                $first = $this->scalarInt($args[0] ?? null) ?? 1;
                $second = $this->scalarInt($args[1] ?? null) ?? 13;

                return '0 '.$first.','.$second.' * * *';

            case 'twiceDailyAt':
                $first = $this->scalarInt($args[0] ?? null);
                $second = $this->scalarInt($args[1] ?? null);
                $minute = $this->scalarInt($args[2] ?? null) ?? 0;
                if ($first === null || $second === null) {
                    return null;
                }

                return $minute.' '.$first.','.$second.' * * *';

            case 'weekly':
                return '0 0 * * 0';
            case 'weeklyOn':
                $day = $this->scalarInt($args[0] ?? null);
                $time = $this->scalarString($args[1] ?? null) ?? '0:00';
                if ($day === null) {
                    // Could be an array — unsupported statically.
                    return null;
                }
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' * * '.$day;

            case 'monthly':
                return '0 0 1 * *';
            case 'monthlyOn':
                $day = $this->scalarInt($args[0] ?? null) ?? 1;
                $time = $this->scalarString($args[1] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$day.' * *';

            case 'twiceMonthly':
                $first = $this->scalarInt($args[0] ?? null) ?? 1;
                $second = $this->scalarInt($args[1] ?? null) ?? 16;
                $time = $this->scalarString($args[2] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                return $minute.' '.$hour.' '.$first.','.$second.' * *';

            case 'lastDayOfMonth':
                $time = $this->scalarString($args[0] ?? null) ?? '0:00';
                [$hour, $minute] = $this->splitTime($time);
                if ($hour === null) {
                    return null;
                }

                // Laravel emits the literal last day at runtime; we can't
                // know the month statically. Use the same convention as
                // Laravel's source by leaving "L" as the day-of-month token.
                return $minute.' '.$hour.' L * *';

            case 'quarterly':
                return '0 0 1 1-12/3 *';
            case 'yearly':
                return '0 0 1 1 *';
            case 'yearlyOn':
                $month = $this->scalarInt($args[0] ?? null) ?? 1;
                $day = $args[1] ?? null;
                $time = $this->scalarString($args[2] ?? null) ?? '0:00';
                $dayInt = $this->scalarInt($day);
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
     * Split a "H:M" string. Returns [null, null] on malformed input.
     *
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
     * Format a chain link as a constraint label, or null if the link is not
     * a recognised constraint helper.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function constraintFor(string $method, array $args): ?string
    {
        if (in_array($method, self::DAY_CONSTRAINTS, true)) {
            return $method;
        }

        if ($method === 'between' || $method === 'unlessBetween') {
            $a = $this->scalarString($args[0] ?? null);
            $b = $this->scalarString($args[1] ?? null);
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
                $s = $this->scalarString($arg);
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function dedupeKey(array $entry): string
    {
        $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
        $line = is_int($entry['line'] ?? null) ? $entry['line'] : 0;
        $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : '';
        $target = is_string($entry['target'] ?? null) ? $entry['target'] : '';

        return $file.'|'.$line.'|'.$kind.'|'.$target;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function iteratePhpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if (! $entry->isFile()) {
                continue;
            }
            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            yield $entry;
        }
    }

    private function relativePath(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
    }
}
