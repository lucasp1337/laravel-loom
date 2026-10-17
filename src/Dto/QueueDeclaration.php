<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * What one class or trait body says about queue configuration: the property
 * defaults it declares, the literal values its constructor sets, and whether
 * that constructor hands over to the parent's.
 *
 * @internal
 */
final readonly class QueueDeclaration
{
    public function __construct(
        public QueueConfigData $properties,
        public QueueConfigData $constructor,
        public bool $declaresConstructor,
        public bool $callsParentConstructor,
    ) {}

    public static function none(): self
    {
        $empty = new QueueConfigData(null, null, null, null, null, null, null);

        return new self($empty, $empty, false, false);
    }
}
