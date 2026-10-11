<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Schedule;

use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Literal;
use PhpParser\Node;

/**
 * Static-literal argument readers shared by the scheduler frequency helpers
 * and constraints.
 *
 * @internal
 */
final class ScheduleArgs
{
    /**
     * Split an `H:i` time literal into [hour, minute]; [null, null] when it is
     * not in that shape.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function splitTime(string $time): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Statically-resolvable day integers from a variadic int list
     * (`days(0, 3)`) or a single array argument (`days([0, 3])`).
     *
     * @return list<int>
     */
    public static function dayArgs(Args $args): array
    {
        $values = [];
        foreach ($args->values() as $value) {
            // `days(0, 3)`: a scalar day
            $int = Literal::int($value);
            if ($int !== null) {
                $values[] = $int;

                continue;
            }
            // `days([0, 3])`: an array of days; non-literal items are skipped
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    $itemInt = Literal::int($item->value);
                    if ($itemInt !== null) {
                        $values[] = $itemInt;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Every item of an array literal as an int, or null when the node is not
     * an array, is empty, or holds a non-integer item.
     *
     * @return list<int>|null
     */
    public static function intArray(?Node\Expr $node): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }

        $values = [];
        foreach ($node->items as $item) {
            $int = Literal::int($item->value);
            if ($int === null) {
                return null;
            }
            $values[] = $int;
        }

        return $values === [] ? null : $values;
    }
}
