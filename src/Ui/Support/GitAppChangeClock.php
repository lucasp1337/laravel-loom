<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

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

    public function lastChange(): ?int
    {
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
