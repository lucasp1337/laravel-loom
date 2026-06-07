<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * Internal typed accessors for hydrating read-model value objects from the
 * decoded index array. Centralises the `mixed` → typed coercion so each
 * `fromArray()` stays declarative and PHPStan-clean.
 *
 * @internal consumed only by {@see \Lucasp\Loom\Index\Model} value objects.
 */
final class Hydrate
{
    /** @param  array<string, mixed>  $data */
    public static function string(array $data, Field $field): string
    {
        $value = $data[$field->value] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param  array<string, mixed>  $data */
    public static function nullableString(array $data, Field $field): ?string
    {
        $value = $data[$field->value] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function int(array $data, Field $field): int
    {
        $value = $data[$field->value] ?? null;

        return is_int($value) ? $value : 0;
    }

    /** @param  array<string, mixed>  $data */
    public static function nullableInt(array $data, Field $field): ?int
    {
        $value = $data[$field->value] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function bool(array $data, Field $field): bool
    {
        return ($data[$field->value] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, Field $field): array
    {
        $value = $data[$field->value] ?? null;
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Hydrate a list of sub-objects through a factory.
     *
     * @template T
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): T  $factory
     * @return list<T>
     */
    public static function list(array $data, Field $field, callable $factory): array
    {
        $value = $data[$field->value] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $factory($item);
            }
        }

        return $out;
    }

    /**
     * Read a nested associative array (e.g. `queue_config`), or null when absent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function nullableArray(array $data, Field $field): ?array
    {
        $value = $data[$field->value] ?? null;

        return is_array($value) ? $value : null;
    }
}
