<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Index\UnresolvedRouteReason;

/** Checks markers against a built index payload; each check returns null or why it failed. */
final readonly class MarkerMatcher
{
    private const RECORDED = '~^(\w+)(?:\[([^\]]+)\])?((?:\.\w+)*)(?:\s+(.+))?$~u';

    /** @param  array<string, mixed>  $index */
    public function __construct(private array $index) {}

    public function failure(Marker $marker): ?string
    {
        return match ($marker->kind) {
            MarkerKind::Recorded => $this->recorded($marker),
            // `not recorded` asks for silence; anything else names an unresolved reason.
            MarkerKind::Unread => $marker->text === Marker::NOT_RECORDED ? $this->silent($marker) : $this->unresolved($marker),
        };
    }

    /**
     * Unresolved entries in the given files that no `✗` marker accounts for.
     *
     * @param  list<Marker>  $markers
     * @param  list<string>  $files
     * @return list<string>
     */
    public function unmarked(array $markers, array $files): array
    {
        $marked = collect($markers)
            ->filter(static fn (Marker $marker): bool => $marker->kind === MarkerKind::Unread)
            ->map(static fn (Marker $marker): string => "{$marker->file}:{$marker->line} {$marker->text}");

        return array_values(collect($this->unresolvedEntries())
            ->filter(static fn (array $entry): bool => in_array($entry['file'], $files, true))
            ->map(static fn (array $entry): string => "{$entry['file']}:{$entry['line']} {$entry['reason']}")
            ->diff($marked)
            ->all());
    }

    private function recorded(Marker $marker): ?string
    {
        if (preg_match(self::RECORDED, $marker->text, $parts) !== 1 || Sections::tryFrom($parts[1]) === null) {
            return "{$marker}: not `section[key].path value` with a known section";
        }

        $section = Sections::from($parts[1]);
        $key = $parts[2] !== '' ? $parts[2] : null;
        $path = array_values(array_filter(explode('.', $parts[3])));
        $expected = self::expected($parts[4] ?? null);

        /** @var list<array<string, mixed>> $entries */
        $entries = $this->index[$section->value] ?? [];
        $selected = array_filter($entries, fn (array $entry): bool => $key === null
            ? $this->at($entry, $marker)
            : self::same($entry[self::identity($section)] ?? null, $key));

        $found = [];
        foreach ($selected as $entry) {
            foreach ($this->walk($entry, $path, null, is_array($expected['value'] ?? null)) as [$value, $located]) {
                // A located item (a dispatch site) on the path must sit on the marker's line.
                $placed = $located === null || $this->at($located, $marker);
                if ($placed && ($expected === null ? $value !== null : self::same($value, $expected['value']))) {
                    return null;
                }
                $found[] = $value;
            }
        }

        return $selected === []
            ? "{$marker}: no {$section->value} entry ".($key === null ? 'at this line' : "for {$key}")
            : "{$marker}: found ".json_encode($found, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function unresolved(Marker $marker): ?string
    {
        if (UnresolvedReason::tryFrom($marker->text) === null && UnresolvedRouteReason::tryFrom($marker->text) === null) {
            return "{$marker}: not a reason or `".Marker::NOT_RECORDED.'`';
        }

        $here = collect($this->unresolvedEntries())->filter(fn (array $entry): bool => $this->at($entry, $marker));

        return $here->contains('reason', $marker->text)
            ? null
            : "{$marker}: unresolved here: ".json_encode($here->pluck('reason')->all());
    }

    private function silent(Marker $marker): ?string
    {
        $traces = $this->traces($this->index, $marker);

        return $traces === [] ? null : "{$marker}: recorded as ".json_encode($traces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The values a path reaches from a node, each with the nearest located item passed on the way.
     *
     * @param  list<string>  $path
     * @param  array<string, mixed>|null  $located
     * @return list<array{0: mixed, 1: array<string, mixed>|null}>
     */
    private function walk(mixed $node, array $path, ?array $located, bool $whole): array
    {
        // A list is stepped through, unless it is the end of the path and the marker compares the whole list.
        if (is_array($node) && array_is_list($node) && ($path !== [] || ! $whole)) {
            return array_merge([], ...array_map(fn (mixed $item): array => $this->walk($item, $path, self::located($item) ?? $located, $whole), $node));
        }

        $segment = array_shift($path);

        return match (true) {
            // End of the path: this node is the value.
            $segment === null => [[$node, $located]],
            // A scalar has no fields.
            ! is_array($node) => [],
            // An optional field the entry leaves out reads as null.
            ! array_key_exists($segment, $node) => $this->walk(null, $path, $located, $whole),
            default => $this->walk($node[$segment], $path, $located, $whole),
        };
    }

    /**
     * Every located item at the marker's line, anywhere in the index.
     *
     * @return list<mixed>
     */
    private function traces(mixed $node, Marker $marker): array
    {
        if (! is_array($node)) {
            return [];
        }

        $here = $this->at($node, $marker) ? [$node] : [];

        return array_merge($here, ...array_map(fn (mixed $child): array => $this->traces($child, $marker), array_values($node)));
    }

    /** @return list<array{file: string, line: int, reason: string}> */
    private function unresolvedEntries(): array
    {
        /** @var list<array{file: string, line: int, reason: string}> */
        return array_merge(
            $this->index[Sections::UNRESOLVED_DISPATCHES->value] ?? [],
            $this->index[Sections::UNRESOLVED_ROUTES->value] ?? [],
        );
    }

    /** @param  array<mixed>  $node */
    private function at(array $node, Marker $marker): bool
    {
        return ($node['file'] ?? null) === $marker->file && ($node['line'] ?? null) === $marker->line;
    }

    /** @return array<string, mixed>|null */
    private static function located(mixed $item): ?array
    {
        return is_array($item) && isset($item['file'], $item['line']) ? $item : null;
    }

    /** The field a `[key]` is matched against. */
    private static function identity(Sections $section): string
    {
        return match ($section) {
            Sections::MODEL_EVENTS => 'id',
            Sections::CLOSURE_LISTENERS => 'event',
            Sections::SCHEDULED_TASKS => 'target',
            Sections::ROUTES => 'uri',
            Sections::UNRESOLVED_DISPATCHES, Sections::UNRESOLVED_ROUTES => 'expression',
            // Every class section is keyed by its class.
            default => 'fqcn',
        };
    }

    /**
     * The value after the path: JSON when it parses, the raw text otherwise; null when absent.
     *
     * @return array{value: mixed}|null
     */
    private static function expected(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return ['value' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw];
    }

    /** Equal, or a string that ends in `\` plus the expected short name. */
    private static function same(mixed $actual, mixed $expected): bool
    {
        return $actual === $expected
            || (is_string($actual) && is_string($expected) && Str::endsWith($actual, '\\'.$expected))
            || (is_array($actual) && is_array($expected) && count($actual) === count($expected)
                && Arr::every(array_keys($expected), static fn (int|string $k): bool => array_key_exists($k, $actual) && self::same($actual[$k], $expected[$k])));
    }
}
