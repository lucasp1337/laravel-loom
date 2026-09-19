<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Closure;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;

/**
 * How one index section is presented: label, columns, empty state and detail target.
 */
final readonly class SectionSpec
{
    /**
     * @param  list<ColumnSpec>  $columns
     * @param  ?Closure  $orphan  item => bool; flags rows the index shows as unconnected
     * @param  ?string  $shortcut  key pressed after `g` to jump here
     */
    public function __construct(
        public Sections $section,
        public string $label,
        public string $emptyTitle,
        public string $emptyBody,
        public array $columns,
        public ?EntityKind $detailKind = null,
        public ?NodeType $type = null,
        public ?Closure $orphan = null,
        public ?string $shortcut = null,
    ) {
    }

    public function isOrphan(object $item): bool
    {
        return $this->orphan !== null && ($this->orphan)($item) === true;
    }
}
