<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use BackedEnum;

/**
 * Turns any read-model entity into label/value rows the generic detail page
 * renders: text, a list of texts, or a table. Class names that exist in the
 * index become links.
 *
 * Walking the read model's own public properties keeps a new field visible
 * without touching a view.
 *
 * @phpstan-type Cell array{text: string, url: ?string}
 * @phpstan-type Row array{label: string, cells: list<Cell>, columns: list<string>, table: list<list<Cell>>}
 */
final class EntityFacts
{
    /** Header data, not repeated as rows. */
    private const HIDDEN = ['fqcn', 'id'];

    public function __construct(private readonly Links $links)
    {
    }

    /**
     * @return list<array{label: string, cells: list<array{text: string, url: ?string}>, columns: list<string>, table: list<list<array{text: string, url: ?string}>>}>
     */
    public function rows(object $entity): array
    {
        $rows = [];

        foreach (get_object_vars($entity) as $property => $value) {
            if (in_array($property, self::HIDDEN, true)) {
                continue;
            }

            $rows[] = $this->row($property, $value);
        }

        return $rows;
    }

    /**
     * @return array{label: string, cells: list<array{text: string, url: ?string}>, columns: list<string>, table: list<list<array{text: string, url: ?string}>>}
     */
    private function row(string $property, mixed $value): array
    {
        $label = ucfirst(str_replace('_', ' ', strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property))));
        $row = ['label' => $label, 'cells' => [], 'columns' => [], 'table' => []];

        if (is_array($value) && $value !== [] && is_object(reset($value))) {
            $first = get_object_vars((object) reset($value));
            $row['columns'] = array_map(static fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), array_keys($first));
            foreach ($value as $item) {
                $row['table'][] = array_map(fn (mixed $v): array => $this->cell($v), array_values(is_object($item) ? get_object_vars($item) : []));
            }

            return $row;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $row['cells'][] = $this->cell($item);
            }

            return $row;
        }

        $row['cells'][] = $this->cell($value);

        return $row;
    }

    /** @return array{text: string, url: ?string} */
    private function cell(mixed $value): array
    {
        $text = match (true) {
            $value === null => "\u{2014}",
            is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => (string) json_encode($this->flatten($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return ['text' => $text, 'url' => is_string($value) ? $this->links->forFqcn($value) : null];
    }

    private function flatten(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map($this->flatten(...), $value) : $value;
    }
}
