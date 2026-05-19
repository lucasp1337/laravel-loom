<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

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

    /**
     * @return array<string, string|int|null>
     */
    public static function emptyConfig(): array
    {
        $config = [];
        foreach (self::PROPERTIES as $property) {
            $config[$property] = null;
        }

        return $config;
    }

    /**
     * @return array<string, string|int|null>
     */
    public static function extractFrom(Node\Stmt\Class_ $node): array
    {
        $config = self::emptyConfig();
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
                $config[$name] = AstHelpers::scalarLiteral($prop->default);
            }
        }

        return $config;
    }
}
