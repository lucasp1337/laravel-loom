<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The parameters of `ApplicationBuilder::withRouting()`, declared in the
 * order of the method signature so a positional argument maps to its case.
 *
 * @internal
 */
enum RoutingParameter: string
{
    case USING = 'using';
    case WEB = 'web';
    case API = 'api';
    case COMMANDS = 'commands';
    case CHANNELS = 'channels';
    case PAGES = 'pages';
    case HEALTH = 'health';
    case API_PREFIX = 'apiPrefix';
    case THEN = 'then';

    /** The parameter a call argument binds to: by name, else by position. */
    public static function forArgument(?string $name, int $position): ?self
    {
        // withRouting(web: ...): a named argument
        if ($name !== null) {
            return self::tryFrom($name);
        }

        // withRouting(null, '...'): a positional argument
        return self::cases()[$position] ?? null;
    }
}
