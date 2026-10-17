<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\EventDiscoveryFact;

/**
 * Decides whether listener discovery runs from the facts read in
 * `bootstrap/app.php` and across the app.
 *
 * `Application::configure()` registers the discovering provider, so discovery
 * is on unless `withEvents(false)` or `disableEventDiscovery()` turns it off.
 * The pre-`configure()` bootstrap registers no such provider: discovery then
 * runs only when an app provider overrides `shouldDiscoverEvents()` to return
 * true. `withEvents([...])`, `setEventDiscoveryPaths()` and
 * `addEventDiscoveryPaths()` replace `app/Listeners`. A missing bootstrap file,
 * a dynamic switch or an unreadable directory gives UNVERIFIED.
 *
 * @internal
 */
final class EventDiscoveryResolver
{
    /**
     * Replaces the `{dir}` marker with the declaring file's app-relative
     * directory and collapses `.` and `..` segments; null when the path
     * leaves the app root.
     */
    public static function anchor(string $marked, string $fileDirectory): ?string
    {
        // An absolute literal path is outside what a relative anchor can express.
        if (Str::startsWith($marked, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', Str::replace('{dir}', $fileDirectory, $marked)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return collect($segments)->implode('/');
    }

    /**
     * @param  list<EventDiscoveryFact>  $bootstrap  facts from bootstrap/app.php
     * @param  list<EventDiscoveryFact>  $app  facts from the scanned files, paths already anchored
     */
    public static function resolve(bool $hasBootstrap, array $bootstrap, array $app): EventDiscovery
    {
        $all = [...$bootstrap, ...$app];
        $facts = collect($all);

        // An explicit off switch wins.
        $off = $facts->contains(fn (EventDiscoveryFact $fact): bool => ($fact->kind === EventDiscoveryFactKind::WITH_EVENTS && $fact->value === false)
            || $fact->kind === EventDiscoveryFactKind::DISABLE);
        if ($off) {
            return new EventDiscovery(EventDiscoveryStatus::DISABLED);
        }

        $dynamic = $facts->contains(fn (EventDiscoveryFact $fact): bool => $fact->dynamic);
        $directories = self::directories($all);
        if ($dynamic || $directories === false) {
            return new EventDiscovery(EventDiscoveryStatus::UNVERIFIED);
        }

        $configure = collect($bootstrap)->contains(fn (EventDiscoveryFact $fact): bool => $fact->kind === EventDiscoveryFactKind::CONFIGURE);
        if ($configure) {
            return new EventDiscovery(EventDiscoveryStatus::ENABLED, $directories);
        }

        // No readable bootstrap, or one that is neither shape: cannot tell.
        $legacy = collect($bootstrap)->contains(fn (EventDiscoveryFact $fact): bool => $fact->kind === EventDiscoveryFactKind::LEGACY_APPLICATION);
        if (! $hasBootstrap || ! $legacy) {
            return new EventDiscovery(EventDiscoveryStatus::UNVERIFIED, $directories);
        }

        // Legacy bootstrap: only a provider override can switch discovery on.
        $overrides = $facts->filter(fn (EventDiscoveryFact $fact): bool => $fact->kind === EventDiscoveryFactKind::OVERRIDE);
        if ($overrides->contains(fn (EventDiscoveryFact $fact): bool => $fact->value === null)) {
            return new EventDiscovery(EventDiscoveryStatus::UNVERIFIED, $directories);
        }

        return $overrides->contains(fn (EventDiscoveryFact $fact): bool => $fact->value === true)
            ? new EventDiscovery(EventDiscoveryStatus::ENABLED, $directories)
            : new EventDiscovery(EventDiscoveryStatus::DISABLED);
    }

    /**
     * Configured directories: `set` replaces, `add` and `withEvents([...])`
     * append. Null when none are set, false when one is unreadable.
     *
     * @param  list<EventDiscoveryFact>  $facts
     * @return list<string>|null|false
     */
    private static function directories(array $facts): array|null|false
    {
        $directories = [];
        foreach ($facts as $fact) {
            $sets = $fact->kind === EventDiscoveryFactKind::SET_PATHS
                || $fact->kind === EventDiscoveryFactKind::ADD_PATHS
                || $fact->kind === EventDiscoveryFactKind::WITH_EVENTS;
            // `withEvents()` with no paths names none.
            if (! $sets || $fact->paths === []) {
                continue;
            }
            if ($fact->paths === null) {
                return false;
            }
            $directories = $fact->kind === EventDiscoveryFactKind::SET_PATHS
                ? $fact->paths
                : [...$directories, ...$fact->paths];
        }

        return $directories === [] ? null : array_values(collect($directories)->unique()->all());
    }
}
