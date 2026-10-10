<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\NodeVisitor;

/**
 * A visitor that collects one record per declared class, read after the walk.
 *
 * @template TRecord of object
 *
 * @internal
 */
interface ClassRecordVisitor extends NodeVisitor
{
    /** @return list<TRecord> */
    public function getClasses(): array;
}
