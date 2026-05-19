<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Node;

/**
 * Centralises the queue-config schema that jobs / mailables /
 * notifications all share: the six recognised property names, the
 * default-null record, and the property-extraction routine that every
 * class visitor used to copy.
 */
final class QueueConfig
{
    /**
     * Property names recognised on a class as queue-config overrides.
     * Their values are scalar literals (string/int/null); anything
     * non-literal stays null.
     *
     * @var list<string>
     */
    public const PROPERTIES = ['connection', 'queue', 'delay', 'tries', 'timeout', 'backoff'];

    /**
     * Default queue-config record — every recognised property mapped to
     * null. Use this as the seed when iterating a class's properties.
     *
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
     * Extract queue-config overrides from the class body. Returns a
     * record where each of {@see self::PROPERTIES} is mapped to its
     * scalar literal default (string / int / null when not declared or
     * not a literal).
     *
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
