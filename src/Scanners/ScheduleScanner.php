<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Dto\ScheduleFrequency;
use Lucasp\Loom\Dto\ScheduleHandoff;
use Lucasp\Loom\Index\FrequencyUnit;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Scanners\Schedule\CronHelper;
use Lucasp\Loom\Scanners\Schedule\ScheduleConstraint;
use Lucasp\Loom\Scanners\Schedule\ScheduleModifier;
use Lucasp\Loom\Scanners\Visitors\ScheduleChainVisitor;
use Lucasp\Loom\Support\AppPath;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\Fqcn;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers entries declared in Laravel's task scheduler (Kernel, bootstrap/app.php,
 * routes/console.php and Schedule facade calls under app/).
 *
 * Four surfaces, merged by (file, line) with the kernel and bootstrap forms
 * preferred over the facade form:
 * 1. `schedule(Schedule $schedule)` in app/Console/Kernel.php;
 * 2. the `->withSchedule(function (Schedule $schedule) {...})` link of
 *    `Application::configure()` in bootstrap/app.php;
 * 3. `Schedule::call/command/job/exec(...)` anywhere under the scan paths;
 * 4. routes/console.php, read with the facade visitor.
 *
 * Facade calls are also read in the providers that `bootstrap/providers.php`
 * and `config/app.php` list, inside or outside the scan paths. A kernel or
 * bootstrap schedule that hands the Schedule to another class's method (see
 * {@see ScheduleChainVisitor}) is followed into that method, located through
 * PSR-4 anywhere in the app root, and on through further handoffs; its tasks
 * count as kernel-form tasks and inherit the `if` guards around each call. A
 * handoff whose class cannot be read or located, or whose method the class
 * does not declare, is reported as a skipped file.
 *
 * One entry per chain, anchored at the root call. `kind` follows the root
 * method; a `->call()` tuple callable or `Class@method` string normalizes to
 * `Class::method`, an inline closure gives a null target. Frequency helpers
 * give a canonical cron (multi-hour helpers honour their minutes argument,
 * `quarterlyOn`, `daysOfMonth` and `everyOddHour` included); the seven
 * sub-minute helpers give `frequency` instead; the last frequency helper in
 * the chain wins across that boundary, as at runtime. A frequency helper
 * followed by a method not known to be safe, or any helper with a non-literal
 * argument, leaves `cron` null rather than guessing. Day and time-window
 * helpers, `when`/`skip` and `environments` become opaque `constraints`: the
 * day helpers verbatim, `between(a,b)` and `unlessBetween(a,b)` with literal
 * times, `when(closure)` and `skip(closure)`, `environments(a,b)`, and
 * `days(0,3)`. A constraint whose arguments are not literal falls back to a
 * marker rather than a guess: `between(closure)` and `unlessBetween(closure)`,
 * `environments(closure)`, and `days(?)`.
 *
 * `->group(Closure)` (variable or facade form, nested groups concatenating)
 * splices the outer chain's links before each inner task's own, so the inner
 * modifiers win. Dispatches inside a scheduled closure are not attributed to
 * the task.
 *
 * @internal
 */
final class ScheduleScanner implements Scanner
{
    use ScannerFilesystem;

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
        'name', 'description', 'user', 'evenInMaintenanceMode', 'purpose', 'describe',
        'ping', 'pingBefore', 'pingBeforeIf', 'thenPing', 'thenPingIf',
        'pingOnSuccess', 'pingOnFailure',
        'onSuccess', 'onFailure', 'then', 'before', 'after',
        'appendOutputTo', 'sendOutputTo', 'emailOutputTo', 'emailOutputOnFailure',
    ];

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    /** @var list<array{handoff: ScheduleHandoff, from: string}> handoffs to follow, with the absolute file of each call */
    private array $handoffs = [];

    public function __construct(?AstWalker $walker = null, ?ScanScope $scope = null, ?Psr4ClassLocator $locator = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->scope = $scope;
        $this->locator = $locator ?? new Psr4ClassLocator;
    }

    /**
     * @return array{scheduled_tasks: list<ScheduledEntry>}
     */
    public function scan(string $appRoot): array
    {
        /** @var array<string, ScheduledEntry> $entries keyed by file|line|kind|target */
        $entries = [];
        $this->handoffs = [];

        foreach ($this->discoverKernelForm($appRoot) as $entry) {
            $entries[$this->dedupeKey($entry)] = $entry;
        }
        foreach ($this->discoverBootstrapForm($appRoot) as $entry) {
            $entries[$this->dedupeKey($entry)] = $entry;
        }
        foreach ($this->followHandoffs($appRoot) as $entry) {
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

            $this->noteUnreadScope($visitor, $file);
            $this->queueHandoffs($visitor, $file);

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
        $this->noteUnreadScope($visitor, $file);
        $this->queueHandoffs($visitor, $file);

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

        foreach ($this->registrationFiles($appRoot, $this->walker) as $file) {
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

    /** @param  list<string>  $outerGuards  guards around the call that reached this file */
    private function queueHandoffs(ScheduleChainVisitor $visitor, string $file, array $outerGuards = []): void
    {
        foreach ($visitor->getHandoffs() as $handoff) {
            $this->handoffs[] = ['handoff' => $handoff->withOuterGuards($outerGuards), 'from' => $file];
        }
    }

    /**
     * Read each method the Schedule was handed to, once per method, queueing
     * the handoffs found there in turn.
     *
     * @return list<ScheduledEntry>
     */
    private function followHandoffs(string $appRoot): array
    {
        $entries = [];
        $seen = [];

        for ($i = 0; $i < count($this->handoffs); $i++) {
            ['handoff' => $handoff, 'from' => $from] = $this->handoffs[$i];
            if (! $handoff->isFollowable()) {
                $this->walker->recordSkip($from, $handoff->line, 'The Schedule is passed to a call whose class cannot be read; the tasks it registers are not read');

                continue;
            }
            if (isset($seen[$handoff->key()])) {
                continue;
            }
            $seen[$handoff->key()] = true;

            $file = $this->locator->locate($appRoot, (string) $handoff->class);
            if ($file === null || AppPath::relative($appRoot, $file) === null || $this->scope()->isExcluded($appRoot, $file)) {
                $this->walker->recordSkip($from, $handoff->line, "The Schedule is passed to {$handoff->class}, whose file is not in the project; the tasks it registers are not read");

                continue;
            }

            $visitor = new ScheduleChainVisitor(ScheduleMode::KERNEL, $handoff);
            if ($this->walker->walk($file, [$visitor]) === null) {
                continue;
            }
            if (! $visitor->foundEntry()) {
                $this->walker->recordSkip($from, $handoff->line, "The Schedule is passed to {$handoff->class}::{$handoff->method}(), which that class does not declare; the tasks it registers are not read");

                continue;
            }

            $this->noteUnreadScope($visitor, $file, followed: true);
            $this->queueHandoffs($visitor, $file, $handoff->guards);
            foreach ($this->translate($visitor->getEntries(), $this->relativePath($appRoot, $file), $handoff->guards) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * A schedule scope with statements that produced no entry means its
     * receivers are not recognised; surface it as a skipped file. A followed
     * class may hold other Schedule methods for other callers, so unreached
     * methods are reported for the kernel only.
     */
    private function noteUnreadScope(ScheduleChainVisitor $visitor, string $file, bool $followed = false): void
    {
        $unreached = $visitor->unreachedHelperLine();
        if ($unreached !== null && ! $followed) {
            $this->walker->recordSkip($file, $unreached, 'A method taking a Schedule is never called from schedule(); its tasks are not read');
        }

        $line = $visitor->bodyScopeLine();
        if ($line === null || $visitor->getEntries() !== [] || $visitor->getHandoffs() !== []) {
            return;
        }

        $this->walker->recordSkip($file, $line, 'No scheduled task could be read from the schedule definition; the receiver is not a Schedule parameter, a typed Schedule property or the Schedule facade');
    }

    /**
     * @param  list<ScheduleChainEntry>  $rawEntries
     * @param  list<string>  $outerGuards  guards around the handoff that reached these entries
     * @return list<ScheduledEntry>
     */
    private function translate(array $rawEntries, string $relativeFile, array $outerGuards = []): array
    {
        $out = [];
        foreach ($rawEntries as $raw) {
            $target = $raw->closureCommand
                ? $this->closureCommandName($raw->rootArgs)
                : $this->resolveTarget($raw->kind, $raw->rootArgs);

            $arguments = $raw->kind === ScheduleKind::COMMAND && ! $raw->closureCommand
                ? ValueLists::commandArguments($raw->rootArgs->valueAt(1))
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
            $withoutOverlappingExpiresAt = null;
            /** @var array<string, true> $modifiers seen ScheduleModifier values */
            $modifiers = [];
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

                $helper = CronHelper::tryFrom($method);
                if ($helper !== null) {
                    // Last-wins, including null when args are unresolvable.
                    $cron = $helper->cron($args, $cron);
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

                $modifier = ScheduleModifier::tryFrom($method);
                if ($modifier !== null) {
                    $modifiers[$modifier->value] = true;
                    match ($modifier) {
                        // `->name('label')`: a string literal only; last literal wins
                        ScheduleModifier::NAME => $name = Literal::string($args->valueAt(0)) ?? $name,
                        // `->timezone('UTC')`: a string literal only; last literal wins
                        ScheduleModifier::TIMEZONE => $timezone = Literal::string($args->valueAt(0)) ?? $timezone,
                        // `->withoutOverlapping(10)`: expiry is the int literal, or null when absent
                        ScheduleModifier::WITHOUT_OVERLAPPING => $withoutOverlappingExpiresAt = Literal::int($args->valueAt(0)),
                        // presence flags, read from $modifiers below
                        ScheduleModifier::ON_ONE_SERVER,
                        ScheduleModifier::RUN_IN_BACKGROUND,
                        ScheduleModifier::EVEN_IN_MAINTENANCE_MODE => null,
                    };

                    continue;
                }

                $constraint = $this->constraintFor($method, $args);
                if ($constraint !== null) {
                    $constraints[] = $constraint;
                }
            }

            $constraints = array_values(collect($constraints)->merge($raw->guards)->merge($outerGuards)->sort()->all());

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
                withoutOverlapping: isset($modifiers[ScheduleModifier::WITHOUT_OVERLAPPING->value]),
                withoutOverlappingExpiresAt: $withoutOverlappingExpiresAt,
                onOneServer: isset($modifiers[ScheduleModifier::ON_ONE_SERVER->value]),
                runInBackground: isset($modifiers[ScheduleModifier::RUN_IN_BACKGROUND->value]),
                evenInMaintenanceMode: isset($modifiers[ScheduleModifier::EVEN_IN_MAINTENANCE_MODE->value]),
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
        $tuple = Callables::staticTarget($value);
        if ($tuple !== null) {
            return $tuple;
        }

        $string = Literal::string($value);
        if ($string !== null) {
            return $this->atCallableToStatic($string);
        }

        return null;
    }

    /** The command name of `Artisan::command('name {arg}', ...)`: the signature's first token. */
    private function closureCommandName(Args $rootArgs): ?string
    {
        $signature = Literal::string($rootArgs->valueAt(0));
        if ($signature === null) {
            return null;
        }

        $name = Str::before(Str::squish($signature), ' ');

        return $name === '' ? null : $name;
    }

    private function atCallableToStatic(string $value): string
    {
        $parts = Fqcn::splitAtMember($value);

        return $parts === null ? $value : $parts[0].'::'.$parts[1];
    }

    private function constraintFor(string $method, Args $args): ?string
    {
        // `->weekdays()`, `->mondays()`, ...: recorded by name
        if (collect(self::DAY_CONSTRAINTS)->containsStrict($method)) {
            return $method;
        }

        return match (ScheduleConstraint::tryFrom($method)) {
            // `->between('8:00', '17:00')`: both bounds literal, else a closure-valued window
            ScheduleConstraint::BETWEEN,
            ScheduleConstraint::UNLESS_BETWEEN => $this->windowConstraint($method, $args),
            // `->when(fn ...)` / `->skip(fn ...)`: the predicate is not statically evaluable
            ScheduleConstraint::WHEN,
            ScheduleConstraint::SKIP => $method.'(closure)',
            // `->environments('staging', ['production'])`
            ScheduleConstraint::ENVIRONMENTS => $this->environmentsConstraint($args),
            // `->days(0, 3)` / `->days([0, 3])`; "days(?)" signals an unresolved arg without fabricating a value
            ScheduleConstraint::DAYS => $this->daysConstraint($args),
            // not a constraint
            null => null,
        };
    }

    private function windowConstraint(string $method, Args $args): string
    {
        $a = Literal::string($args->valueAt(0));
        $b = Literal::string($args->valueAt(1));

        return $a !== null && $b !== null ? $method.'('.$a.','.$b.')' : $method.'(closure)';
    }

    private function environmentsConstraint(Args $args): string
    {
        $values = $args->stringValues();

        return $values === [] ? 'environments(closure)' : 'environments('.Arr::join($values, ',').')';
    }

    private function daysConstraint(Args $args): string
    {
        $values = $args->intValues();

        return $values === [] ? 'days(?)' : 'days('.Arr::join($values, ',').')';
    }

    private function dedupeKey(ScheduledEntry $entry): string
    {
        return $entry->file.'|'.$entry->line.'|'.$entry->kind->value.'|'.($entry->target ?? '');
    }
}
