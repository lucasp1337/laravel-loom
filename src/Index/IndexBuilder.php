<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Index;

use JsonSchema\Validator;
use Lucasp\Atlas\Contracts\Scanner;
use RuntimeException;

/**
 * Orchestrates scanners, merges their output, validates against the canonical
 * schema, and produces the final Index value object.
 */
class IndexBuilder
{
    public const ATLAS_VERSION = '0.1.0';

    /** @var array<int, Scanner> */
    private array $scanners = [];

    public function register(Scanner $scanner): void
    {
        $this->scanners[] = $scanner;
    }

    /**
     * Run every registered scanner against $appRoot and assemble the Index.
     */
    public function build(string $appRoot, string $laravelVersion): Index
    {
        /** @var array<string, array<int, array<string, mixed>>> $sections */
        $sections = [
            'events' => [],
            'listeners' => [],
            'observers' => [],
            'model_events' => [],
            'unresolved_dispatches' => [],
        ];

        foreach ($this->scanners as $scanner) {
            foreach ($scanner->scan($appRoot) as $section => $entries) {
                if (! array_key_exists($section, $sections)) {
                    throw new RuntimeException("Scanner returned unknown section: {$section}");
                }
                $sections[$section] = array_merge($sections[$section], $entries);
            }
        }

        return new Index(
            atlasVersion: self::ATLAS_VERSION,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
            laravelVersion: $laravelVersion,
            events: $sections['events'],
            modelEvents: $sections['model_events'],
            listeners: $sections['listeners'],
            observers: $sections['observers'],
            unresolvedDispatches: $sections['unresolved_dispatches'],
        );
    }

    /**
     * Validate an index payload against schema/atlas-index.schema.json.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        $schemaPath = dirname(__DIR__, 2).'/schema/atlas-index.schema.json';
        if (! is_file($schemaPath)) {
            throw new RuntimeException("Schema not found at {$schemaPath}");
        }

        $validator = new Validator;
        $data = json_decode((string) json_encode($payload));
        $validator->validate($data, (object) ['$ref' => 'file://'.$schemaPath]);

        if ($validator->isValid()) {
            return [];
        }

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = sprintf('[%s] %s', $error['property'] ?? '', $error['message'] ?? '');
        }

        return $errors;
    }
}
