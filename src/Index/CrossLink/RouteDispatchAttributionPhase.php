<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Attributes each dispatch site to the route(s) whose controller method
 * encloses it — populating `routes[*].dispatches[]`. Mirrors
 * {@see DispatchAttributionPhase}'s site handling (closure-internal sites are
 * excluded, the shared {@see DispatchEntry::fromSite} payload is reused) so a
 * controller-method dispatch surfaces identically on routes and on listeners.
 */
final class RouteDispatchAttributionPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $routeIndex = $this->indexRoutes($context);

        foreach ($context->dispatchSites as $site) {
            // Closure-internal sites belong to a closure listener, not to the
            // enclosing class method; ClosureDispatchAttributionPhase owns them.
            if (($site['inClosure'] ?? false) === true) {
                continue;
            }

            $payload = DispatchEntry::fromSite($site);
            if ($payload === null) {
                continue;
            }

            // Routing keys live on the site, not in the shared payload.
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site[Field::METHOD->value] ?? null;
            if (! is_string($classFqcn) || ! is_string($method)) {
                continue;
            }

            $key = $classFqcn.'::'.$method;
            foreach ($routeIndex[$key] ?? [] as $routeIdx) {
                $context->appendToEntry(Sections::ROUTES, $routeIdx, Field::DISPATCHES->value, $payload);
            }
        }
    }

    /**
     * Build "{controller_fqcn}::{controller_method}" → route entry indexes.
     * Routes without a resolved controller (closures) are skipped, so they
     * never receive dispatches.
     *
     * @return array<string, list<int>>
     */
    private function indexRoutes(CrossLinkContext $context): array
    {
        $index = [];
        foreach ($context->sections[Sections::ROUTES->value] as $idx => $route) {
            $fqcn = $route[Field::CONTROLLER_FQCN->value] ?? null;
            $method = $route[Field::CONTROLLER_METHOD->value] ?? null;
            if (! is_string($fqcn) || ! is_string($method)) {
                continue;
            }

            $index[$fqcn.'::'.$method][] = $idx;
        }

        return $index;
    }
}
