<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use BackedEnum;
use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\ObserverRegistration;
use Lucasp\Loom\Index\RouteKind;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\Summarized;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use RuntimeException;

/**
 * Schema enum fields whose values have a one-line meaning, keyed by the field
 * path the schema reference prints, and the PHP enum that holds the meanings.
 */
enum SchemaEnum: string
{
    case ListenerRegistration = 'listeners[].registration';
    case ClosureListenerRegistration = 'closure_listeners[].registration';
    case ObserverRegistration = 'observers[].registration';
    case ScheduleKind = 'scheduled_tasks[].kind';
    case RouteKind = 'routes[].kind';
    case UnresolvedReason = 'unresolved_dispatches[].reason';
    case UnresolvedRouteReason = 'unresolved_routes[].reason';
    case DispatchMode = 'dispatchSite.mode';
    case Confidence = 'dispatch.confidence';

    /**
     * The meaning of each schema value, in schema order; fails on a schema
     * value the PHP enum lacks.
     *
     * @param  list<string>  $values
     * @return array<string, string> value => summary
     */
    public function summaries(array $values): array
    {
        $enum = $this->enum();
        $cases = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
        if (array_diff($values, $cases) !== []) {
            throw new RuntimeException("Schema values of {$this->value} are not all cases of {$enum}");
        }

        return array_combine($values, array_map(static fn (string $value): string => $enum::from($value)->summary(), $values));
    }

    /** @return class-string<BackedEnum&Summarized> */
    private function enum(): string
    {
        return match ($this) {
            self::ListenerRegistration, self::ClosureListenerRegistration => ListenerRegistration::class,
            self::ObserverRegistration => ObserverRegistration::class,
            self::ScheduleKind => ScheduleKind::class,
            self::RouteKind => RouteKind::class,
            self::UnresolvedReason => UnresolvedReason::class,
            self::UnresolvedRouteReason => UnresolvedRouteReason::class,
            self::DispatchMode => DispatchMode::class,
            self::Confidence => Confidence::class,
        };
    }
}
