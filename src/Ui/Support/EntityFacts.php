<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use BackedEnum;
use Illuminate\Support\Str;
use Lucasp\Loom\Ui\Dto\FactCell;
use Lucasp\Loom\Ui\Dto\FactRow;

/**
 * Turns any read-model entity into label/value rows the generic detail page
 * renders: text, a list of texts, or a table. Class names that exist in the
 * index become links.
 *
 * Walking the read model's own public properties keeps a new field visible
 * without touching a view.
 */
final class EntityFacts
{
    /** Header data, not repeated as rows. */
    private const HIDDEN = ['fqcn', 'id'];

    public function __construct(private readonly Links $links)
    {
    }

    /** @return list<FactRow> */
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

    private function row(string $property, mixed $value): FactRow
    {
        $label = Str::headline($property);

        if (is_array($value) && $value !== [] && is_object(reset($value))) {
            $columns = array_map(Str::headline(...), array_keys(get_object_vars((object) reset($value))));
            $table = [];
            foreach ($value as $item) {
                $table[] = array_map(fn (mixed $v): FactCell => $this->cell($v), array_values(is_object($item) ? get_object_vars($item) : []));
            }

            return new FactRow($label, columns: $columns, table: $table);
        }

        $items = is_array($value) ? $value : [$value];

        return new FactRow($label, cells: array_map(fn (mixed $v): FactCell => $this->cell($v), array_values($items)));
    }

    private function cell(mixed $value): FactCell
    {
        $text = match (true) {
            $value === null => "\u{2014}",
            is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => (string) json_encode($this->flatten($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return new FactCell($text, is_string($value) ? $this->links->forFqcn($value) : null);
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
