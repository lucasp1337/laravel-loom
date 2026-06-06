<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Rules;

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\Contracts\CheckRule;
use Lucasp\Loom\Check\Result\Violation;
use Lucasp\Loom\Check\RuleKey;
use Lucasp\Loom\Index\SchemaValidator;

/**
 * Asserts the index conforms to the published JSON schema. Each schema error
 * surfaces as its own violation.
 */
final class SchemaValidationRule implements CheckRule
{
    public function __construct(
        private readonly SchemaValidator $validator = new SchemaValidator,
    ) {
    }

    public function key(): RuleKey
    {
        return RuleKey::SCHEMA;
    }

    public function description(): string
    {
        return 'Index validates against the JSON schema';
    }

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        foreach ($this->validator->validate($context->index()) as $error) {
            $violations[] = new Violation($error, ['detail' => $error]);
        }

        return $violations;
    }
}
