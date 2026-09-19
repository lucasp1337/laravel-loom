<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Last commit touching app/, best effort: any git problem reads as "unknown".
 */
final class GitAppChangeClock implements AppChangeClock
{
    public function __construct(private readonly string $basePath)
    {
    }

    private const TTL_SECONDS = 30;

    public function lastChange(): ?int
    {
        // 0 caches an "unknown" result so a missing git isn't retried every render.
        try {
            $cached = Cache::remember('loom.app_change.'.md5($this->basePath), self::TTL_SECONDS, fn (): int => $this->probe() ?? 0);
        } catch (Throwable) {
            // An unavailable cache store (e.g. a database store without its table) must not break the page.
            $cached = $this->probe() ?? 0;
        }

        return $cached > 0 ? $cached : null;
    }

    private function probe(): ?int
    {
        if (! class_exists(Process::class)) {
            return null;
        }

        try {
            $process = new Process(['git', 'log', '-1', '--format=%ct', '--', 'app'], $this->basePath, null, null, 3);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $out = trim($process->getOutput());

        return $process->isSuccessful() && ctype_digit($out) ? (int) $out : null;
    }
}
