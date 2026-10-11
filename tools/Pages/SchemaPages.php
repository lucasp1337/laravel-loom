<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The schema reference: one region for the top-level fields, one per
 * section and one per shared shape, all read from the JSON schema.
 */
final class SchemaPages implements RegionSource
{
    private const PAGE = 'docs/reference/schema.md';

    /** Enums longer than this move out of the Type column. */
    private const INLINE_ENUM_LIMIT = 5;

    /** @var array<string, string> def name => top-level key, for the section entry shapes */
    private array $sectionKeys = [];

    /** @var array<string, list<string>> field path => long enum values already printed */
    private array $longEnums = [];

    public function __construct(private readonly string $root) {}

    public function regions(): array
    {
        $schema = $this->load();
        $human = require $this->root.'/tools/reference/schema.php';
        $defs = $schema['$defs'];
        $this->longEnums = [];

        $regions = [];
        $regions['schema:top'] = $this->top($schema, $human['sections']);

        foreach ($schema['properties'] as $key => $property) {
            if (! isset($property['items']['$ref'])) {
                continue;
            }
            $def = $defs[Str::after($property['items']['$ref'], '#/$defs/')];
            $regions["schema:{$key}"] = $this->defSection(2, "`{$key}[]`", "{$key}[]", $def);
        }

        foreach ($defs as $name => $def) {
            if (isset($this->sectionKeys[$name])) {
                continue;
            }
            $regions["shape:{$name}"] = $this->defSection(3, "`{$name}`", $name, $def);
        }

        return Arr::map($regions, static fn (string $body): array => ['page' => self::PAGE, 'body' => $body]);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $schema = json_decode((string) file_get_contents($this->root.'/schema/loom-index.schema.json'), true, 512, JSON_THROW_ON_ERROR);
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
     * @param  array<string, string>  $sections
     */
    private function top(array $schema, array $sections): string
    {
        $rows = [];
        foreach ($schema['properties'] as $key => $property) {
            if (isset($property['items']['$ref'])) {
                $description = $sections[$key] ?? throw new RuntimeException("tools/reference/schema.php lacks a description for section {$key}");
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
     * A field table for an object node, followed by tables for inline nested objects.
     *
     * @param  array<string, mixed>  $object
     */
    private function fieldTable(array $object, string $path, int $nestedLevel): string
    {
        $required = $object['required'] ?? [];
        $rows = [];
        $nested = [];

        foreach ($object['properties'] as $field => $node) {
            $fieldPath = "{$path}.{$field}";
            [$type, $inline, $values] = $this->typeOf($node);
            $description = (string) ($node['description'] ?? '');
            [$type, $description] = $this->withEnum($type, $description, $values, $fieldPath);

            $rows[] = [
                '`'.$field.'`'.(in_array($field, $required, true) ? '' : '?'),
                $type,
                Markdown::cell($description),
            ];
            if ($inline !== null && isset($inline['properties']) && $this->hasDescriptions($inline)) {
                $suffix = ($node['type'] ?? null) === 'array' ? '[]' : '';
                $nestedPath = $fieldPath.$suffix;
                $nested[] = str_repeat('#', $nestedLevel)." `{$nestedPath}`\n\n".$this->fieldTable($inline, $nestedPath, $nestedLevel + 1);
            }
        }

        $table = Markdown::table(['Field', 'Type', 'Description'], $rows);

        return $nested === [] ? $table : $table."\n\n".implode("\n\n", $nested);
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
     * Long enums leave the Type column and are listed in the description;
     * a list that is a subset of an earlier one says so instead of repeating.
     *
     * @param  list<string>|null  $values
     * @return array{0: string, 1: string}
     */
    private function withEnum(string $type, string $description, ?array $values, string $path): array
    {
        if ($values === null || count($values) <= self::INLINE_ENUM_LIMIT) {
            return [$type, $description];
        }

        foreach ($this->longEnums as $earlierPath => $earlier) {
            $missing = array_values(array_diff($earlier, $values));
            if (array_diff($values, $earlier) === [] && $missing !== []) {
                $list = $description === '' ? '' : $description."\n";

                return [$type, $list.'Same values as `'.$earlierPath.'` without '.Markdown::codeList($missing).'.'];
            }
        }
        $this->longEnums[$path] = $values;

        return [$type, ($description === '' ? '' : $description."\n").'Values: '.Markdown::codeList($values).'.'];
    }

    /**
     * Human type for a schema node, the inline object schema it carries, and
     * the enum values when they are too many to sit in the Type column.
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
            foreach ($node['oneOf'] as $branch) {
                [$label, $branchInline] = $this->typeOf($branch);
                $labels[] = $label;
                $inline ??= $branchInline;
            }
            $null = in_array('null', $labels, true);
            $rest = array_values(array_diff($labels, ['null']));

            return [implode(' or ', $rest).($null ? ' or null' : ''), $inline, null];
        }
        if (isset($node['enum'])) {
            return $this->enumType($node['enum'], 'one of');
        }

        $type = $node['type'] ?? 'any';
        if (is_array($type)) {
            return [implode(' or ', $type), null, null];
        }
        if ($type === 'array') {
            [$label, $inline, $values] = $this->typeOf($node['items'] ?? []);
            if ($values !== null) {
                return ['array of enum', $inline, $values];
            }

            return ["array of {$label}", $inline, null];
        }
        if ($type === 'object') {
            return ['object', $node, null];
        }
        if ($type === 'integer' && isset($node['minimum'])) {
            return ["integer ≥ {$node['minimum']}", null, null];
        }

        return [(string) $type, null, null];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: null, 2: list<string>|null}
     */
    private function enumType(array $values, string $prefix): array
    {
        if (count($values) > self::INLINE_ENUM_LIMIT) {
            return ['enum', null, $values];
        }

        return ["{$prefix} ".Markdown::codeList($values), null, null];
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
