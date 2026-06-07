<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use JsonException;

/**
 * Hydrates an {@see Index} from a Loom index JSON document — the inverse of
 * {@see Index::toArray()}. This is the supported entry point for library
 * consumers (UI, MCP server, custom tooling) that read a previously written
 * `index.json` rather than running a scan in-process.
 */
final class IndexLoader
{
    /**
     * Load and hydrate an index from a JSON file on disk.
     *
     * @throws IndexLoadException when the file is unreadable or malformed
     */
    public function fromFile(string $path): Index
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw IndexLoadException::unreadable($path);
        }

        return $this->fromJson($raw);
    }

    /**
     * Hydrate an index from a JSON string.
     *
     * @throws IndexLoadException when the JSON is invalid or not an object
     */
    public function fromJson(string $json): Index
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw IndexLoadException::invalidJson($e->getMessage());
        }

        if (! is_array($decoded)) {
            throw IndexLoadException::notAnObject();
        }

        /** @var array<string, mixed> $decoded */
        return $this->fromArray($decoded);
    }

    /**
     * Hydrate an index from an already-decoded associative array.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws IndexLoadException when a required envelope field is absent
     */
    public function fromArray(array $data): Index
    {
        $sections = [];
        foreach (SectionRegistry::names() as $name) {
            $section = $data[$name] ?? [];
            $sections[$name] = is_array($section) ? array_values($section) : [];
        }

        /** @var array<string, array<int, array<string, mixed>>> $sections */
        return new Index(
            loomVersion: $this->meta($data, MetaField::LOOM_VERSION),
            scannedAt: $this->meta($data, MetaField::SCANNED_AT),
            laravelVersion: $this->meta($data, MetaField::LARAVEL_VERSION),
            sections: $sections,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function meta(array $data, MetaField $field): string
    {
        $value = $data[$field->value] ?? null;
        if (! is_string($value)) {
            throw IndexLoadException::missingMeta($field->value);
        }

        return $value;
    }
}
