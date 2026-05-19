<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\QueueConfigData;
use PhpParser\Node;

/**
 * Extracts the six queueable-class properties (`connection`, `queue`,
 * `delay`, `tries`, `timeout`, `backoff`) declared as scalar literals
 * on a class. Properties not declared map to `null`.
 */
final class QueueConfig
{
    /** @var list<string> */
    public const PROPERTIES = ['connection', 'queue', 'delay', 'tries', 'timeout', 'backoff'];

    public static function extractFrom(Node\Stmt\Class_ $node): QueueConfigData
    {
        /** @var array<string, string|int|null> $values */
        $values = array_fill_keys(self::PROPERTIES, null);
        $names = array_flip(self::PROPERTIES);

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                if (! isset($names[$name])) {
                    continue;
                }
                if ($prop->default === null) {
                    continue;
                }
                $values[$name] = AstHelpers::scalarLiteral($prop->default);
            }
        }

        return new QueueConfigData(
            connection: $values['connection'],
            queue: $values['queue'],
            delay: $values['delay'],
            tries: $values['tries'],
            timeout: $values['timeout'],
            backoff: $values['backoff'],
        );
    }
}
