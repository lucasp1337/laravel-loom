<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use JsonSchema\Validator;
use RuntimeException;

/**
 * Validates an index payload against `schema/loom-index.schema.json`.
 *
 * Extracted from `IndexBuilder` so building and validation are separately
 * testable. `IndexBuilder::validate()` remains as a thin delegate for
 * call-site ergonomics — most consumers want both operations on the same
 * object.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        $schemaPath = dirname(__DIR__, 2).'/schema/loom-index.schema.json';
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
