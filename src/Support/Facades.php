<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Laravel facade FQCNs Loom matches against. `matches()` accepts both
 * the FQCN and the bare-alias form (the basename of the FQCN, which
 * Laravel auto-aliases by default).
 */
enum Facades: string
{
    case EVENT = 'Illuminate\\Support\\Facades\\Event';
    case BUS = 'Illuminate\\Support\\Facades\\Bus';
    case MAIL = 'Illuminate\\Support\\Facades\\Mail';
    case NOTIFICATION = 'Illuminate\\Support\\Facades\\Notification';
    case SCHEDULE = 'Illuminate\\Support\\Facades\\Schedule';

    public function matches(string $className): bool
    {
        if ($className === $this->value) {
            return true;
        }

        $lastBackslash = strrpos($this->value, '\\');
        $alias = $lastBackslash === false ? $this->value : substr($this->value, $lastBackslash + 1);

        return $className === $alias;
    }
}
