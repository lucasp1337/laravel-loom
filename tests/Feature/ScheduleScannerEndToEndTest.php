<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\ScheduleScanner;

function scheduleEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/schedule-fixture-app';
}

/**
 * @return array<string, mixed>
 */
function buildScheduleEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);
    $builder->register(new ScheduleScanner);

    return $builder->build(scheduleEndToEndFixturePath(), '12.x')->toArray();
}

it('produces a schema-valid index with ScheduleScanner registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);
    $builder->register(new ScheduleScanner);

    $payload = $builder->build(scheduleEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('reports stats.scheduled equal to the count of scheduled entries', function () {
    $payload = buildScheduleEndToEndPayload();

    expect($payload)->toHaveKey('scheduled');
    expect($payload['stats'])->toHaveKey('scheduled');
    expect($payload['stats']['scheduled'])->toBe(count($payload['scheduled']));
    expect($payload['stats']['scheduled'])->toBeGreaterThan(0);
});

it('serialises the new frequency/constraint entries with the expected cron and constraints', function () {
    $payload = buildScheduleEndToEndPayload();

    /** @var array<int, array<string, mixed>> $scheduled */
    $scheduled = $payload['scheduled'];

    $byTarget = [];
    foreach ($scheduled as $entry) {
        $byTarget[(string) $entry['target']] = $entry;
    }

    expect($byTarget['odd:default']['cron'])->toBe('0 1-23/2 * * *');
    expect($byTarget['quarter:args']['cron'])->toBe('30 14 15 1-12/3 *');
    expect($byTarget['twohours:atminute']['cron'])->toBe('15 */2 * * *');

    expect($byTarget['days:array']['cron'])->toBe('0 0 * * *');
    expect($byTarget['days:array']['constraints'])->toContain('days(1,5)');
});

it('sorts the scheduled array by (file, line) ascending in the built index', function () {
    $payload = buildScheduleEndToEndPayload();

    /** @var array<int, array<string, mixed>> $scheduled */
    $scheduled = $payload['scheduled'];

    $tuples = array_map(
        static fn (array $e): array => [
            str_replace(DIRECTORY_SEPARATOR, '/', (string) $e['file']),
            (int) $e['line'],
        ],
        $scheduled,
    );

    $sorted = $tuples;
    usort($sorted, static function (array $a, array $b): int {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });

    expect($tuples)->toBe($sorted);
});
