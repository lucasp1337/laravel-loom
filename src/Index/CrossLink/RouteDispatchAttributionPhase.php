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
 *
 * @internal
 */
final class RouteDispatchAttributionPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $routeIndex = $this->indexRoutes($context);

        foreach ($context->dispatchSites as $site) {
            $payload = DispatchEntry::forHandler($site);
            if ($payload === null) {
                continue;
            }

            // Closure-owned sites belong to the route whose closure span holds
            // them (listener closures match no route); every other site matches
            // by controller method.
            if (($site['inClosure'] ?? false) === true) {
                $this->attributeToClosureRoutes($context, $site, $payload);

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
     * @param  array<string, mixed>  $site
     * @param  array<string, mixed>  $payload
     */
    private function attributeToClosureRoutes(CrossLinkContext $context, array $site, array $payload): void
    {
        $file = $site[Field::FILE->value] ?? null;
        $line = $site[Field::LINE->value] ?? null;
        if (! is_string($file) || ! is_int($line)) {
            return;
        }

        foreach ($context->sections[Sections::ROUTES->value] as $idx => $route) {
            $start = $route[Field::LINE->value] ?? null;
            $end = $route[Field::END_LINE->value] ?? null;
            if (! is_int($start) || ! is_int($end)) {
                continue;
            }

            if (($route[Field::FILE->value] ?? null) === $file && $line >= $start && $line <= $end) {
                $context->appendToEntry(Sections::ROUTES, $idx, Field::DISPATCHES->value, $payload);
            }
        }
    }

    /**
     * Build "{controller_fqcn}::{controller_method}" → route entry indexes.
     * Routes without a resolved controller are skipped here.
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
