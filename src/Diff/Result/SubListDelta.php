<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

use Lucasp\Loom\Index\Field;

/**
 * Membership change in a single sublist field of a matched entry.
 *
 * @internal
 */
final readonly class SubListDelta
{
    /**
     * Wrapper key under which a scalar sublist member is normalized for
     * identity comparison; not a schema field, hence a sentinel rather than a
     * {@see Field} case.
     */
    public const SCALAR_MEMBER_KEY = 'value';

    /**
     * Members are emitted in their original shape: an associative array for
     * structured sublists (e.g. dispatch sites) or a plain scalar for scalar
     * sublists (e.g. hooks, constraints).
     *
     * @param  list<mixed>  $added
     * @param  list<mixed>  $removed
     */
    public function __construct(
        public string $field,
        public array $added,
        public array $removed,
    ) {}
}
