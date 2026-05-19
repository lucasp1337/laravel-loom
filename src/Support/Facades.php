<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Laravel facade FQCNs Loom matches against, as a string-backed enum.
 *
 * A facade call in user code can appear as either the fully-qualified
 * `Illuminate\Support\Facades\Event::dispatch(...)` or the imported alias
 * `Event::dispatch(...)`. NameResolver attaches the resolved FQCN to the
 * `Name` node, but the bare alias remains possible when parsing outside
 * a namespace context. `matches()` accepts both forms — pass the enum
 * case for type safety; the helper handles FQCN-vs-alias internally.
 */
enum Facades: string
{
    case EVENT = 'Illuminate\\Support\\Facades\\Event';
    case BUS = 'Illuminate\\Support\\Facades\\Bus';
    case MAIL = 'Illuminate\\Support\\Facades\\Mail';
    case NOTIFICATION = 'Illuminate\\Support\\Facades\\Notification';
    case SCHEDULE = 'Illuminate\\Support\\Facades\\Schedule';

    /**
     * True when `$className` refers to this facade, accepting either the
     * canonical FQCN or its bare-alias form. NameResolver normally
     * rewrites bare aliases to the FQCN, but parsing outside a namespace
     * context (or before aliases are registered) can leave the bare form.
     *
     * Laravel's default `config/app.php` aliases the basename of every
     * facade FQCN — so we derive the alias from the FQCN itself rather
     * than maintain an explicit alias map.
     */
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
