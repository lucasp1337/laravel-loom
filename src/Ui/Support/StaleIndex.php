<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Decides whether the snapshot predates the last change to app/.
 */
final class StaleIndex
{
    public function __construct(private readonly AppChangeClock $clock)
    {
    }

    /** Human duration ("6 days") when stale, otherwise null. */
    public function olderBy(string $scannedAt): ?string
    {
        $changed = $this->clock->lastChange();
        if ($changed === null) {
            return null;
        }

        try {
            $scanned = CarbonImmutable::parse($scannedAt);
        } catch (Throwable) {
            return null;
        }

        $commit = CarbonImmutable::createFromTimestamp($changed);
        if ($commit->lessThanOrEqualTo($scanned)) {
            return null;
        }

        $days = (int) floor(($commit->getTimestamp() - $scanned->getTimestamp()) / 86400);
        if ($days >= 1) {
            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        return $scanned->diffForHumans($commit, ['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }
}
