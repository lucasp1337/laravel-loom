<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use JsonSchema\Validator;
use RuntimeException;

/**
 * Validates an index payload against schema/loom-index.schema.json.
 * Throws RuntimeException when the schema file is missing.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
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
