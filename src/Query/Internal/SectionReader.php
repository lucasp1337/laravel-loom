<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Internal;

use BackedEnum;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\Model\ClosureListener;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Job;
use Lucasp\Loom\Index\Model\Listener;
use Lucasp\Loom\Index\Model\ModelEvent;
use Lucasp\Loom\Index\Model\Observer;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Model\Scheduled;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;
use Lucasp\Loom\Index\Sections;

/**
 * Uniform access to any section's read-model items: the display name, file and
 * fan counts that list sorting/filtering and search need.
 *
 * @internal
 */
final class SectionReader
{
    /** @return list<object> */
    public static function items(Index $index, Sections $section): array
    {
        return match ($section) {
            Sections::EVENTS => $index->events(),
            Sections::LISTENERS => $index->listeners(),
            Sections::OBSERVERS => $index->observers(),
            Sections::MODEL_EVENTS => $index->modelEvents(),
            Sections::JOBS => $index->jobs(),
            Sections::UNRESOLVED_DISPATCHES => $index->unresolvedDispatches(),
            Sections::CLOSURE_LISTENERS => $index->closureListeners(),
            Sections::SCHEDULED => $index->scheduled(),
            Sections::MAILABLES => $index->mailables(),
            Sections::NOTIFICATIONS => $index->notifications(),
            Sections::ROUTES => $index->routes(),
        };
    }

    public static function name(object $item): string
    {
        return match (true) {
            $item instanceof Route => $item->method.' '.$item->uri,
            $item instanceof ClosureListener => $item->event,
            $item instanceof ModelEvent => $item->id,
            $item instanceof UnresolvedDispatch => $item->file.':'.$item->line,
            $item instanceof Scheduled => $item->name ?? $item->target ?? $item->kind->value,
            default => self::stringProperty($item, 'fqcn'),
        };
    }

    /** Display path for routes ("/orders"), the name otherwise. */
    public static function path(object $item): string
    {
        return $item instanceof Route ? '/'.ltrim($item->uri, '/') : self::name($item);
    }

    public static function file(object $item): string
    {
        return self::stringProperty($item, 'file');
    }

    public static function handlerCount(object $item): int
    {
        return match (true) {
            $item instanceof Event => count($item->handledBy),
            $item instanceof Listener => count($item->handles),
            default => 0,
        };
    }

    public static function dispatchCount(object $item): int
    {
        return match (true) {
            $item instanceof Event => count($item->dispatchedFrom),
            $item instanceof Listener,
            $item instanceof Observer,
            $item instanceof Job,
            $item instanceof ClosureListener,
            $item instanceof Route => count($item->dispatches),
            default => 0,
        };
    }

    /** Normalised comparable value of a public property, or null when absent. */
    public static function comparable(object $item, string $property): ?string
    {
        if (! property_exists($item, $property)) {
            return null;
        }

        return self::normalise($item->{$property});
    }

    public static function normalise(mixed $value): ?string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    private static function stringProperty(object $item, string $property): string
    {
        $value = property_exists($item, $property) ? $item->{$property} : null;

        return is_string($value) ? $value : '';
    }
}
