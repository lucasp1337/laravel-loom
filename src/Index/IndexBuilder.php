<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Contracts\Scanner;
use RuntimeException;

/**
 * Runs registered scanners, merges their sections, cross-links relations,
 * and produces an Index.
 */
class IndexBuilder
{
    public const LOOM_VERSION = '0.3.0';

    /** @var array<int, Scanner> */
    private array $scanners = [];

    private SchemaValidator $validator;

    private IndexSerializer $serializer;

    private CrossLinker $crossLinker;

    public function __construct(
        ?SchemaValidator $validator = null,
        ?IndexSerializer $serializer = null,
        ?CrossLinker $crossLinker = null,
    ) {
        $this->validator = $validator ?? new SchemaValidator;
        $this->serializer = $serializer ?? new IndexSerializer;
        $this->crossLinker = $crossLinker ?? new CrossLinker;
    }

    public function register(Scanner $scanner): void
    {
        $this->scanners[] = $scanner;
    }

    /**
     * Underscore-prefixed sections (e.g. `_dispatch_sites`) are merged but
     * stripped before the Index is constructed.
     */
    public function build(string $appRoot, string $laravelVersion): Index
    {
        $sections = $this->initialSections();

        foreach ($this->scanners as $scanner) {
            foreach ($scanner->scan($appRoot) as $section => $entries) {
                if (str_starts_with($section, '_')) {
                    $sections[$section] ??= [];
                    $sections[$section] = array_merge($sections[$section], $entries);

                    continue;
                }

                if (! array_key_exists($section, $sections)) {
                    throw new RuntimeException("Scanner returned unknown section: {$section}");
                }
                $sections[$section] = array_merge($sections[$section], $entries);
            }
        }

        $sections = $this->serializeSections($sections);
        $sections = $this->crossLinker->crossLink($sections);
        $this->stripInternalSections($sections);

        return $this->buildIndex($sections, $laravelVersion);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        return $this->validator->validate($payload);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function initialSections(): array
    {
        $sections = [];
        foreach (Sections::cases() as $section) {
            $sections[$section->value] = [];
        }

        return $sections;
    }

    /**
     * Convert scanner-emitted DTO entries into schema-shaped arrays. Internal
     * sections (underscore-prefixed) are serialized too — cross-link reads
     * them as arrays — and stripped after cross-link.
     *
     * @param  array<string, array<int, object|array<string, mixed>>>  $sections
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function serializeSections(array $sections): array
    {
        $serialized = [];
        foreach ($sections as $name => $entries) {
            $serialized[$name] = $this->serializer->section($name, array_values($entries));
        }

        return $serialized;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function stripInternalSections(array &$sections): void
    {
        foreach (array_keys($sections) as $key) {
            if (str_starts_with($key, '_')) {
                unset($sections[$key]);
            }
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function buildIndex(array $sections, string $laravelVersion): Index
    {
        return new Index(
            loomVersion: self::LOOM_VERSION,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
            laravelVersion: $laravelVersion,
            sections: $sections,
        );
    }
}
