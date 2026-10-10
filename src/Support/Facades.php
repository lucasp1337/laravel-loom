<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Laravel facade FQCNs Loom matches against. `matches()` accepts both
 * the FQCN and the bare-alias form (the basename of the FQCN, which
 * Laravel auto-aliases by default).
 *
 * @internal
 */
enum Facades: string
{
    case EVENT = 'Illuminate\\Support\\Facades\\Event';
    case BUS = 'Illuminate\\Support\\Facades\\Bus';
    case QUEUE = 'Illuminate\\Support\\Facades\\Queue';
    case MAIL = 'Illuminate\\Support\\Facades\\Mail';
    case NOTIFICATION = 'Illuminate\\Support\\Facades\\Notification';
    case SCHEDULE = 'Illuminate\\Support\\Facades\\Schedule';
    case ROUTE = 'Illuminate\\Support\\Facades\\Route';

    public function matches(string $className): bool
    {
        if ($className === $this->value) {
            return true;
        }

        return $className === Fqcn::short($this->value);
    }
}
