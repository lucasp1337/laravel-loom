<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Tools\Reference\SchemaEnum;
use Lucasp\Loom\Tools\Reference\SchemaSection;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;
use RuntimeException;

/**
 * The schema reference: one region for the top-level fields, one per
 * section and one per shared shape, all read from the JSON schema.
 */
final class SchemaPages implements RegionSource
{
    /** Enums longer than this get a value list even without meanings. */
    private const INLINE_ENUM_LIMIT = 5;

    /** @var array<string, string> def name => top-level key, for the section entry shapes */
    private array $sectionKeys = [];

    /** @var array<string, list<string>> field path => enum values already listed */
    private array $listedEnums = [];

    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        $schema = $this->load();
        $defs = $schema['$defs'];
        $this->listedEnums = [];

        $regions = [new GeneratedRegion(Region::SchemaTop, $this->top($schema))];

        foreach ($schema['properties'] as $key => $property) {
            if (! isset($property['items']['$ref'])) {
                continue;
            }
            $def = $defs[Str::after($property['items']['$ref'], '#/$defs/')];
            $regions[] = new GeneratedRegion(
                RegionName::keyed(RegionPrefix::SchemaSection, $key),
                $this->defSection(2, "`{$key}[]`", "{$key}[]", $def),
            );
        }

        foreach ($defs as $name => $def) {
            if (isset($this->sectionKeys[$name])) {
                continue;
            }
            $regions[] = new GeneratedRegion(
                RegionName::keyed(RegionPrefix::SchemaShape, $name),
                $this->defSection(3, "`{$name}`", $name, $def),
            );
        }

        return $regions;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $schema = json_decode((string) file_get_contents($this->context->root.'/schema/loom-index.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->sectionKeys = [];
        foreach ($schema['properties'] as $key => $property) {
            if (isset($property['items']['$ref'])) {
                $this->sectionKeys[Str::after($property['items']['$ref'], '#/$defs/')] = $key;
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function top(array $schema): string
    {
        $rows = [];
        foreach ($schema['properties'] as $key => $property) {
            if (isset($property['items']['$ref'])) {
                $description = SchemaSection::tryFrom($key)?->description()
                    ?? throw new RuntimeException("Add a case for section {$key} to ".SchemaSection::class);
                $rows[] = ["`{$key}`", "array of [`{$key}[]`](#".Markdown::slug("{$key}[]").')', Markdown::cell($description)];

                continue;
            }
            [$type] = $this->typeOf($property);
            $rows[] = ["`{$key}`", $type, Markdown::cell((string) ($property['description'] ?? ''))];
        }

        return "## Top-level fields\n\n".Markdown::table(['Field', 'Type', 'Description'], $rows);
    }

    /** @param  array<string, mixed>  $def */
    private function defSection(int $level, string $heading, string $path, array $def): string
    {
        $parts = [str_repeat('#', $level).' '.$heading];
        if (($def['description'] ?? '') !== '') {
            $parts[] = (string) $def['description'];
        }
        if (isset($def['properties'])) {
            $parts[] = $this->fieldTable($def, $path, $level + 1);
        }

        return implode("\n\n", $parts);
    }

    /**
     * A field table for an object node, followed by the value lists of its
     * listed enums and tables for inline nested objects.
     *
     * @param  array<string, mixed>  $object
     */
    private function fieldTable(array $object, string $path, int $nestedLevel): string
    {
        $required = $object['required'] ?? [];
        $rows = [];
        $enums = [];
        $nested = [];

        foreach ($object['properties'] as $field => $node) {
            $fieldPath = "{$path}.{$field}";
            [$type, $inline, $values] = $this->typeOf($node);
            $enum = SchemaEnum::tryFrom($fieldPath);
            $listed = $values !== null && ($enum !== null || count($values) > self::INLINE_ENUM_LIMIT);

            $type = match (true) {
                // not an enum
                $values === null => $type,
                // linked to its value list under the table
                $listed => Str::replaceLast('enum', '[enum](#'.Markdown::slug($fieldPath).')', $type),
                // few values with no meanings: inline
                default => Str::replaceLast('enum', 'one of '.Markdown::codeList($values), $type),
            };
            if ($listed) {
                $enums[] = $this->enumList($fieldPath, $values, $enum, $nestedLevel);
            }

            $rows[] = [
                '`'.$field.'`'.(in_array($field, $required, true) ? '' : '?'),
                $type,
                Markdown::cell((string) ($node['description'] ?? '')),
            ];
            if ($inline !== null && isset($inline['properties']) && $this->hasDescriptions($inline)) {
                $suffix = ($node['type'] ?? null) === 'array' ? '[]' : '';
                $nestedPath = $fieldPath.$suffix;
                $nested[] = str_repeat('#', $nestedLevel)." `{$nestedPath}`\n\n".$this->fieldTable($inline, $nestedPath, $nestedLevel + 1);
            }
        }

        return implode("\n\n", [Markdown::table(['Field', 'Type', 'Description'], $rows), ...$enums, ...$nested]);
    }

    /**
     * The values of one enum field: a value and meaning table when the PHP
     * enum has meanings, else the values; a subset of an earlier list links there.
     *
     * @param  list<string>  $values
     */
    private function enumList(string $path, array $values, ?SchemaEnum $enum, int $level): string
    {
        $heading = str_repeat('#', $level)." `{$path}`\n\n";

        foreach ($this->listedEnums as $earlierPath => $earlier) {
            $missing = array_values(array_diff($earlier, $values));
            if (array_diff($values, $earlier) === [] && $missing !== []) {
                return $heading.'Same values as [`'.$earlierPath.'`](#'.Markdown::slug($earlierPath).') without '.Markdown::codeList($missing).'.';
            }
        }
        $this->listedEnums[$path] = $values;

        if ($enum === null) {
            return $heading.Markdown::codeList($values).'.';
        }

        $rows = Arr::map($enum->summaries($values), static fn (string $summary, string $value): array => ["`{$value}`", Markdown::cell(Str::ucfirst($summary))]);

        return $heading.Markdown::table(['Value', 'Meaning'], array_values($rows));
    }

    /**
     * Whether any property carries a description; a table of bare keys adds nothing.
     *
     * @param  array<string, mixed>  $object
     */
    private function hasDescriptions(array $object): bool
    {
        return Arr::first($object['properties'], static fn (array $node): bool => ($node['description'] ?? '') !== '') !== null;
    }

    /**
     * Human type for a schema node, the inline object schema it carries, and
     * its enum values; an enum's type reads `enum` until the field table
     * places the values.
     *
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: array<string, mixed>|null, 2: list<string>|null}
     */
    private function typeOf(array $node): array
    {
        if (isset($node['$ref'])) {
            return [$this->refLabel($node['$ref']), null, null];
        }
        if (isset($node['oneOf'])) {
            $labels = [];
            $inline = null;
            $values = null;
            foreach ($node['oneOf'] as $branch) {
                [$label, $branchInline, $branchValues] = $this->typeOf($branch);
                $labels[] = $label;
                $inline ??= $branchInline;
                $values ??= $branchValues;
            }
            $null = in_array('null', $labels, true);
            $rest = array_values(array_diff($labels, ['null']));

            return [implode(' or ', $rest).($null ? ' or null' : ''), $inline, $values];
        }
        if (isset($node['enum'])) {
            return ['enum', null, $node['enum']];
        }

        $type = $node['type'] ?? 'any';
        if (is_array($type)) {
            return [implode(' or ', $type), null, null];
        }
        if ($type === 'array') {
            [$label, $inline, $values] = $this->typeOf($node['items'] ?? []);

            return ["array of {$label}", $inline, $values];
        }
        if ($type === 'object') {
            return ['object', $node, null];
        }
        if ($type === 'integer' && isset($node['minimum'])) {
            return ["integer ≥ {$node['minimum']}", null, null];
        }

        return [(string) $type, null, null];
    }

    private function refLabel(string $ref): string
    {
        $name = Str::after($ref, '#/$defs/');
        if (isset($this->sectionKeys[$name])) {
            return 'object';
        }

        return '['.($name === 'relativePath' ? 'path' : $name).'](#'.Markdown::slug($name).')';
    }
}
