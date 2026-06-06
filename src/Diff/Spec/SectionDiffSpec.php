<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Spec;

use Closure;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Per-section diff descriptor consumed by the generic comparator: how to key
 * an entry ({@see $identity}), which scalar/list fields are compared for change
 * ({@see $semanticFields}), and which repeated sublists are diffed by
 * membership ({@see $subLists}).
 */
final class SectionDiffSpec
{
    /**
     * @param  Closure(array<string,mixed>): string  $identity
     * @param  list<Field>  $semanticFields
     * @param  list<SubListSpec>  $subLists
     */
    public function __construct(
        public readonly Sections $section,
        public readonly Closure $identity,
        public readonly array $semanticFields,
        public readonly array $subLists = [],
    ) {
    }
}
