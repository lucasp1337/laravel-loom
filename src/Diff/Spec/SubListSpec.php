<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Spec;

use Closure;
use Lucasp\Loom\Index\Field;

/**
 * Describes how to diff a repeated sublist on a section entry (e.g. an event's
 * `handled_by`). Membership is keyed by {@see $memberIdentity}; a member that
 * appears on only one side is an add or a remove.
 */
final class SubListSpec
{
    /**
     * @param  Closure(array<string,mixed>): string  $memberIdentity
     */
    public function __construct(
        public readonly Field $field,
        public readonly Closure $memberIdentity,
    ) {
    }
}
