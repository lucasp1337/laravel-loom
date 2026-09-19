<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Internal;

use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\Model\ClosureListener;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\Dto\SearchHit;
use Lucasp\Loom\Query\EntityKind;

/**
 * Case-insensitive scan over the searchable sections.
 *
 * Scores: exact full name 100, exact short name 90, short-name prefix 70,
 * substring of the full name 50, substring of the file path 20.
 */
final class Searcher
{
    private const EXACT = 100;

    private const EXACT_SHORT = 90;

    private const PREFIX = 70;

    private const CONTAINS = 50;

    private const IN_FILE = 20;

    private const SECTIONS = [
        Sections::EVENTS,
        Sections::LISTENERS,
        Sections::OBSERVERS,
        Sections::JOBS,
        Sections::MAILABLES,
        Sections::NOTIFICATIONS,
        Sections::ROUTES,
        Sections::CLOSURE_LISTENERS,
    ];

    public function __construct(private readonly Index $index)
    {
    }

    /** @return list<SearchHit> */
    public function search(string $term, int $limit): array
    {
        $needle = strtolower(trim($term));
        if ($needle === '' || $limit < 1) {
            return [];
        }

        $hits = [];
        foreach (self::SECTIONS as $section) {
            foreach (SectionReader::items($this->index, $section) as $item) {
                $hit = $this->hit($section, $item, $needle);
                if ($hit !== null) {
                    $hits[] = $hit;
                }
            }
        }

        usort($hits, static fn (SearchHit $a, SearchHit $b): int => [$b->score, $a->label, $a->section->value]
            <=> [$a->score, $b->label, $b->section->value]);

        return array_slice($hits, 0, $limit);
    }

    private function hit(Sections $section, object $item, string $needle): ?SearchHit
    {
        $name = SectionReader::name($item);
        $file = SectionReader::file($item);
        $short = $this->shortName($item, $name);

        $score = $this->score($needle, strtolower($name), strtolower($short), strtolower($file), $item);
        if ($score === 0) {
            return null;
        }

        $line = property_exists($item, 'line') && is_int($item->line) ? $item->line : 0;

        return new SearchHit(
            $section,
            EntityKind::forSection($section),
            $name,
            $file.':'.$line,
            $score,
            $item instanceof ClosureListener ? $file.':'.$line : $name,
        );
    }

    private function score(string $needle, string $name, string $short, string $file, object $item): int
    {
        // A route's name is a second exact-match handle alongside "VERB uri".
        $routeName = $item instanceof Route && $item->name !== null ? strtolower($item->name) : null;

        return match (true) {
            $needle === $name => self::EXACT,
            $needle === $short || $needle === $routeName => self::EXACT_SHORT,
            str_starts_with($short, $needle) => self::PREFIX,
            str_contains($name, $needle) || ($routeName !== null && str_contains($routeName, $needle)) => self::CONTAINS,
            $file !== '' && str_contains($file, $needle) => self::IN_FILE,
            default => 0,
        };
    }

    private function shortName(object $item, string $name): string
    {
        if ($item instanceof Route) {
            return ltrim($item->uri, '/');
        }

        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
