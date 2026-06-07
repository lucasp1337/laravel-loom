<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Support\Arr;

/**
 * Renders benchmark results as a human-readable text report or as JSON.
 */
final class BenchReport
{
    /**
     * @param  list<BenchResult>  $results
     */
    public static function text(array $results): string
    {
        $lines = ['Loom benchmark', str_repeat('=', 60), ''];

        $lines[] = self::row(['profile', 'files', 'build', 'peak mem', 'entries'], [10, 8, 12, 12, 9]);
        $lines[] = str_repeat('-', 60);
        foreach ($results as $result) {
            $lines[] = self::row([
                $result->profile,
                (string) $result->files,
                self::ms($result->buildMilliseconds),
                self::mem($result->peakMemoryBytes),
                (string) array_sum($result->sections),
            ], [10, 8, 12, 12, 9]);
        }
        $lines[] = '';

        foreach ($results as $result) {
            $lines[] = "{$result->profile} — per-scanner ({$result->files} files)";
            $lines[] = self::row(['scanner', 'time', 'entries'], [22, 12, 9]);
            $lines[] = str_repeat('-', 44);
            foreach ($result->scanners as $stat) {
                $lines[] = self::row([
                    $stat->scanner,
                    self::ms($stat->milliseconds),
                    (string) $stat->entries(),
                ], [22, 12, 9]);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<BenchResult>  $results
     */
    public static function json(array $results): string
    {
        $payload = Arr::map($results, fn (BenchResult $r): array => $r->toArray());

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<string>  $cells
     * @param  list<int>  $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $out = '';
        foreach ($cells as $i => $cell) {
            $out .= str_pad($cell, $widths[$i] ?? 10);
        }

        return rtrim($out);
    }

    private static function ms(float $milliseconds): string
    {
        return number_format($milliseconds, 1).' ms';
    }

    private static function mem(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1).' MB';
    }
}
