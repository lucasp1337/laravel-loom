<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Source shapes that decide whether and where event discovery runs.
 *
 * @internal
 */
enum EventDiscoveryFactKind
{
    /** `Application::configure(...)`, which registers discovery by default. */
    case CONFIGURE;

    /** `new Application(...)`, the pre-`configure()` bootstrap. */
    case LEGACY_APPLICATION;

    /** `->withEvents(...)`: `value` false turns discovery off, `paths` replaces the directories. */
    case WITH_EVENTS;

    /** `EventServiceProvider::disableEventDiscovery()`. */
    case DISABLE;

    /** `EventServiceProvider::setEventDiscoveryPaths([...])`. */
    case SET_PATHS;

    /** `EventServiceProvider::addEventDiscoveryPaths(...)`. */
    case ADD_PATHS;

    /** `shouldDiscoverEvents()` overridden on a provider: `value` is the literal return, null when dynamic. */
    case OVERRIDE;
}
