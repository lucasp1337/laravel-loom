<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Decides who owns each closure-internal dispatch site. A registration closure
 * (a closure listener) owns every site inside its source span. A site in any
 * other closure (`DB::transaction(fn () => ...)`, `->each()`, `tap()`,
 * `afterCommit()`) is pass-through: its tag is cleared so the class-handler
 * phases attribute it to the enclosing method.
 *
 * Runs before the attribution phases, which read the cleared tag.
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

            if (! $this->isOwned($site, $spans)) {
                $context->dispatchSites[$i]['inClosure'] = false;
            }
        }
    }

    /**
     * @return list<array{file: string, start: int, end: int}>
     */
    private function registrationSpans(CrossLinkContext $context): array
    {
        $spans = [];

        foreach ($context->sections[Sections::CLOSURE_LISTENERS->value] ?? [] as $closure) {
            $file = $closure[Field::FILE->value] ?? null;
            $start = $closure[Field::LINE->value] ?? null;
            $end = $closure[Field::END_LINE->value] ?? null;

            if (is_string($file) && is_int($start) && is_int($end)) {
                $spans[] = ['file' => $file, 'start' => $start, 'end' => $end];
            }
        }

        return $spans;
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  list<array{file: string, start: int, end: int}>  $spans
     */
    private function isOwned(array $site, array $spans): bool
    {
        $file = $site[Field::FILE->value] ?? null;
        $line = $site[Field::LINE->value] ?? null;
        if (! is_string($file) || ! is_int($line)) {
            return false;
        }

        foreach ($spans as $span) {
            if ($span['file'] === $file && $line >= $span['start'] && $line <= $span['end']) {
                return true;
            }
        }

        return false;
    }
}
