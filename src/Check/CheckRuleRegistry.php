<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check;

use Lucasp\Loom\Check\Contracts\CheckRule;
use Lucasp\Loom\Check\Rules\CyclicDispatchRule;
use Lucasp\Loom\Check\Rules\OrphanEventsRule;
use Lucasp\Loom\Check\Rules\OrphanListenersRule;
use Lucasp\Loom\Check\Rules\SchemaValidationRule;
use Lucasp\Loom\Check\Rules\UnresolvedDispatchesRule;
use Lucasp\Loom\Index\SchemaValidator;

/**
 * Single source of truth for the check rules and the order in which they run.
 * The list order is the report order.
 */
final class CheckRuleRegistry
{
    public function __construct(
        private readonly SchemaValidator $validator = new SchemaValidator,
    ) {
    }

    /**
     * @return list<CheckRule>
     */
    public function rules(): array
    {
        return [
            new SchemaValidationRule($this->validator),
            new OrphanListenersRule,
            new OrphanEventsRule,
            new UnresolvedDispatchesRule,
            new CyclicDispatchRule,
        ];
    }
}
