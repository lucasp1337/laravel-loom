<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * A fixture-app size profile. `tiny` mirrors a fresh `laravel new`; `medium`
 * (~200 app files) and `large` (~2000) stress the scanners at scale. Category
 * counts scale from a single base distribution so the shape stays constant and
 * only the volume grows.
 */
final readonly class BenchProfile
{
    private const BASE = [
        'events' => 2,
        'listeners' => 2,
        'observers' => 1,
        'jobs' => 2,
        'mailables' => 1,
        'notifications' => 1,
        'services' => 3,
        'controllers' => 1,
        'models' => 1,
    ];

    private const FACTORS = [
        'tiny' => 1,
        'medium' => 14,
        'large' => 145,
    ];

    /**
     * @param  array<string, int>  $counts  category => number of files
     */
    private function __construct(
        public string $name,
        public array $counts,
    ) {
    }

    public static function tiny(): self
    {
        return self::scaled('tiny');
    }

    public static function medium(): self
    {
        return self::scaled('medium');
    }

    public static function large(): self
    {
        return self::scaled('large');
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::tiny(), self::medium(), self::large()];
    }

    public static function named(string $name): self
    {
        if (! isset(self::FACTORS[$name])) {
            throw new InvalidArgumentException("Unknown bench profile: {$name}");
        }

        return self::scaled($name);
    }

    public function count(string $category): int
    {
        return $this->counts[$category] ?? 0;
    }

    /**
     * Total PHP files written, including the always-present Kernel, providers,
     * bootstrap, and route files.
     */
    public function totalFiles(): int
    {
        return array_sum($this->counts) + AppGenerator::FIXED_FILES;
    }

    private static function scaled(string $name): self
    {
        $factor = self::FACTORS[$name];

        return new self($name, Arr::map(self::BASE, fn (int $n): int => $n * $factor));
    }
}
