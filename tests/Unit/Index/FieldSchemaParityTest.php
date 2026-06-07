<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\MetaField;
use Lucasp\Loom\Index\Sections;

/**
 * Resolve the JSON schema path relative to this test file.
 * tests/Unit/Index/ -> repo root is three levels up.
 */
function loomSchemaPath(): string
{
    return dirname(__DIR__, 3).'/schema/loom-index.schema.json';
}

/**
 * Recursively collect every object property-key string declared anywhere in the
 * decoded schema: each time a node is an array carrying an associative
 * `properties` map, record its keys, then recurse into all values so inline
 * objects (e.g. `event.handled_by[].items.properties`) are captured too.
 *
 * @param  array<mixed>  $node
 * @param  list<string>  $keys
 */
function collectSchemaPropertyKeys(array $node, array &$keys): void
{
    if (isset($node['properties']) && is_array($node['properties'])) {
        foreach (array_keys($node['properties']) as $key) {
            $keys[] = (string) $key;
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            collectSchemaPropertyKeys($value, $keys);
        }
    }
}

/**
 * @return list<string>
 */
function loomSchemaPropertyKeys(): array
{
    $path = loomSchemaPath();
    expect($path)->toBeFile();

    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded)->toBeArray();

    $keys = [];
    collectSchemaPropertyKeys($decoded, $keys);

    return array_values(array_unique($keys));
}

/**
 * @return list<string>
 */
function fieldCaseValues(): array
{
    return array_map(static fn (Field $f): string => $f->value, Field::cases());
}

it('has no orphan Field cases: every Field value exists as a schema property', function () {
    $schemaKeys = loomSchemaPropertyKeys();

    foreach (Field::cases() as $case) {
        expect($schemaKeys)->toContain($case->value);
    }
});

it('has no drift: schema property keys minus sections and meta equal the Field cases', function () {
    $schemaKeys = loomSchemaPropertyKeys();

    $sectionValues = array_map(static fn (Sections $s): string => $s->value, Sections::cases());
    $metaValues = array_map(static fn (MetaField $m): string => $m->value, MetaField::cases());

    // Subtracting sections also removes the stats sub-keys, which reuse the section names.
    $remaining = array_values(array_diff($schemaKeys, $sectionValues, $metaValues));
    sort($remaining);

    $fieldValues = fieldCaseValues();
    sort($fieldValues);

    expect($remaining)->toBe($fieldValues);
});

it('does not treat the diff scalar-member wrapper key "value" as a schema property', function () {
    $schemaKeys = loomSchemaPropertyKeys();

    expect($schemaKeys)->not->toContain('value');
});

it('locks the Field enum case count as a recount canary', function () {
    expect(Field::cases())->toHaveCount(54);
});

it('exposes every MetaField case as a top-level schema property', function () {
    $path = loomSchemaPath();
    $decoded = json_decode((string) file_get_contents($path), true);

    $topLevel = array_keys($decoded['properties']);

    foreach (MetaField::cases() as $case) {
        expect($topLevel)->toContain($case->value);
    }
});

it('exposes every Sections case as a top-level schema property', function () {
    $path = loomSchemaPath();
    $decoded = json_decode((string) file_get_contents($path), true);

    $topLevel = array_keys($decoded['properties']);

    foreach (Sections::cases() as $case) {
        expect($topLevel)->toContain($case->value);
    }
});
