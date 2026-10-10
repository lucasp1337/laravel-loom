<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Index\FrequencyUnit;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\ModelHook;
use Lucasp\Loom\Index\ObserverRegistration;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\UnresolvedReason;

/**
 * Every closed enum in the schema must equal its PHP source of truth, so a
 * value added on one side only fails loudly.
 */
function schemaDefs(): array
{
    $path = dirname(__DIR__, 3).'/schema/loom-index.schema.json';

    return json_decode((string) file_get_contents($path), true)['$defs'];
}

/** @param  class-string<BackedEnum>  $enum */
function enumValues(string $enum): array
{
    return array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases());
}

it('keeps schema enums in step with their PHP enums', function (): void {
    $defs = schemaDefs();

    $pairs = [
        'listener.registration' => [$defs['listener']['properties']['registration']['enum'], enumValues(ListenerRegistration::class)],
        'observer.registration' => [$defs['observer']['properties']['registration']['enum'], enumValues(ObserverRegistration::class)],
        'observer.hooks' => [$defs['observer']['properties']['hooks']['items']['enum'], ModelHook::observableValues()],
        'dispatch.confidence' => [$defs['dispatch']['properties']['confidence']['enum'], enumValues(Confidence::class)],
        'dispatchSite.mode' => [$defs['dispatchSite']['properties']['mode']['enum'], enumValues(DispatchMode::class)],
        'scheduleEntry.kind' => [$defs['scheduleEntry']['properties']['kind']['enum'], enumValues(ScheduleKind::class)],
        'scheduleEntry.frequency.unit' => [$defs['scheduleEntry']['properties']['frequency']['oneOf'][1]['properties']['unit']['enum'], enumValues(FrequencyUnit::class)],
        'unresolvedDispatch.reason' => [$defs['unresolvedDispatch']['properties']['reason']['enum'], enumValues(UnresolvedReason::class)],
    ];

    foreach ($pairs as $label => [$schema, $php]) {
        expect($schema)->toEqualCanonicalizing($php, $label);
    }
});

it('keeps the closure listener registration enum a subset of the listener one', function (): void {
    $defs = schemaDefs();

    expect($defs['listener']['properties']['registration']['enum'])
        ->toContain(...$defs['closureListener']['properties']['registration']['enum']);
});
