<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use Illuminate\Support\Arr;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\Dto\SearchHit;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\IndexQuery;

/**
 * Builds UI URLs for index entities. One place that knows the route names.
 */
final class Links
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    public function dashboard(): string
    {
        return route('loom.dashboard');
    }

    public function section(Sections $section, ?string $search = null): string
    {
        return route('loom.section', Arr::whereNotNull(['section' => $section->value, 'q' => $search]));
    }

    public function entity(EntityKind $kind, string $fqcn): string
    {
        if ($kind === EntityKind::EVENT) {
            return route('loom.event', ['fqcn' => Fqcn::toSlug($fqcn)]);
        }

        return route('loom.entity', ['section' => $kind->section()->value, 'fqcn' => Fqcn::toSlug($fqcn)]);
    }

    public function chain(string $eventFqcn, ?int $depth = null): string
    {
        return route('loom.chain', Arr::whereNotNull(['fqcn' => Fqcn::toSlug($eventFqcn), 'depth' => $depth]));
    }

    public function hit(SearchHit $hit): string
    {
        if ($hit->kind !== null) {
            return $this->entity($hit->kind, $hit->detailRef);
        }

        // Closure refs are `file:line`; the index filter matches the file alone.
        $search = $hit->section === Sections::CLOSURE_LISTENERS
            ? preg_replace('/:\d+$/', '', $hit->detailRef)
            : $hit->detailRef;

        return $this->section($hit->section, $search);
    }

    /** Page for a class that appears in the index under any entity kind, or null. */
    public function forFqcn(string $fqcn): ?string
    {
        foreach (EntityKind::cases() as $kind) {
            if ($this->query->entity($kind, $fqcn) !== null) {
                return $this->entity($kind, $fqcn);
            }
        }

        return null;
    }
}
