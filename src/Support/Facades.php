<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Centralises Laravel facade FQCNs and the alias-vs-FQCN comparison that
 * every dispatch-recognising visitor needs.
 *
 * A facade call in user code can appear as either the fully-qualified
 * `Illuminate\Support\Facades\Event::dispatch(...)` or the imported alias
 * `Event::dispatch(...)`. NameResolver attaches the resolved FQCN to the
 * `Name` node, but the bare alias remains possible when the alias matches
 * the basename of the FQCN. `matches()` accepts both forms and answers
 * "does this `Name->toString()` refer to facade X?".
 *
 * Constants are the canonical FQCNs; consumers should pass them to
 * `matches()` rather than re-declaring the strings locally.
 */
final class Facades
{
    public const EVENT = 'Illuminate\\Support\\Facades\\Event';

    public const BUS = 'Illuminate\\Support\\Facades\\Bus';

    public const MAIL = 'Illuminate\\Support\\Facades\\Mail';

    public const NOTIFICATION = 'Illuminate\\Support\\Facades\\Notification';

    public const SCHEDULE = 'Illuminate\\Support\\Facades\\Schedule';

    /**
     * Map of canonical FQCN → bare alias. Laravel's default
     * `config/app.php` aliases the basename of each facade, so the alias
     * equals the FQCN's last namespace segment for every facade in scope.
     * If a future facade is added whose alias diverges from the basename,
     * extend this map and `matches()` will pick it up.
     */
    private const ALIASES = [
        self::EVENT => 'Event',
        self::BUS => 'Bus',
        self::MAIL => 'Mail',
        self::NOTIFICATION => 'Notification',
        self::SCHEDULE => 'Schedule',
    ];

    /**
     * True when `$className` refers to `$facadeFqcn`, accepting either the
     * canonical FQCN or its bare-alias form.
     *
     * NameResolver normally rewrites bare aliases to the FQCN, but parsing
     * a file outside a namespace context (or before aliases are registered)
     * can leave the bare form. Callers should use this helper rather than
     * the inlined `$x === FQCN || $x === 'Alias'` pattern.
     */
    public static function matches(string $className, string $facadeFqcn): bool
    {
        return $className === $facadeFqcn
            || $className === (self::ALIASES[$facadeFqcn] ?? null);
    }
}
