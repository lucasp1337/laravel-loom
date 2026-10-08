<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Decides who owns each closure-internal dispatch site. A registration closure
 * (a closure listener or a closure route) owns every site inside its source
 * span. A site in any other closure (`DB::transaction(fn () => ...)`, `->each()`, `tap()`,
 * `afterCommit()`) is pass-through: its tag is cleared so the class-handler
 * phases attribute it to the enclosing method.
 *
 * A site owned by a route closure also gets a `closureOrigin` label so
 * {@see DispatchedFromPhase} can record the route as its origin. Closure
 * listeners have no such edge.
 *
 * Runs before the attribution phases, which read the cleared tag.
 *
 * @internal
 */
final class ClosureOwnershipPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $spans = $this->registrationSpans($context);

        foreach ($context->dispatchSites as $i => $site) {
            if (($site['inClosure'] ?? false) !== true) {
                continue;
            }

            $owner = $this->owner($site, $spans);
            if ($owner === null) {
                $context->dispatchSites[$i]['inClosure'] = false;

                continue;
            }

            if ($owner['origin'] !== null) {
                $context->dispatchSites[$i]['closureOrigin'] = $owner['origin'];
            }
        }
    }

    /**
     * @return list<array{file: string, start: int, end: int, origin: ?string}>
     */
    private function registrationSpans(CrossLinkContext $context): array
    {
        $spans = [];

        foreach ($context->sections[Sections::CLOSURE_LISTENERS->value] ?? [] as $closure) {
            $file = $closure[Field::FILE->value] ?? null;
            $start = $closure[Field::LINE->value] ?? null;
            $end = $closure[Field::END_LINE->value] ?? null;

            if (is_string($file) && is_int($start) && is_int($end)) {
                $spans[] = ['file' => $file, 'start' => $start, 'end' => $end, 'origin' => null];
            }
        }

        foreach ($context->sections[Sections::ROUTES->value] ?? [] as $route) {
            $file = $route[Field::FILE->value] ?? null;
            $start = $route[Field::LINE->value] ?? null;
            $end = $route[Field::END_LINE->value] ?? null;
            $verb = $route[Field::METHOD->value] ?? null;
            $uri = $route[Field::URI->value] ?? null;

            if (is_string($file) && is_int($start) && is_int($end) && is_string($verb) && is_string($uri)) {
                $spans[] = ['file' => $file, 'start' => $start, 'end' => $end, 'origin' => $verb.' '.$uri];
            }
        }

        return $spans;
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  list<array{file: string, start: int, end: int, origin: ?string}>  $spans
     * @return array{origin: ?string}|null
     */
    private function owner(array $site, array $spans): ?array
    {
        $file = $site[Field::FILE->value] ?? null;
        $line = $site[Field::LINE->value] ?? null;
        if (! is_string($file) || ! is_int($line)) {
            return null;
        }

        foreach ($spans as $span) {
            if ($span['file'] === $file && $line >= $span['start'] && $line <= $span['end']) {
                return ['origin' => $span['origin']];
            }
        }

        return null;
    }
}
