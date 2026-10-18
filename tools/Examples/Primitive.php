<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

/** A directory of `docs/examples/`, rendered as one page under `docs/reference/detection/`. Page order is case order. */
enum Primitive: string
{
    case Events = 'events';
    case Listeners = 'listeners';
    case ClosureListeners = 'closure-listeners';
    case Observers = 'observers';
    case Jobs = 'jobs';
    case Dispatches = 'dispatches';
    case Mailables = 'mailables';
    case Notifications = 'notifications';
    case Schedule = 'schedule';
    case Routes = 'routes';
    case Scope = 'scope';

    public function title(): string
    {
        return match ($this) {
            self::Events => 'Events',
            self::Listeners => 'Listeners',
            self::ClosureListeners => 'Closure listeners',
            self::Observers => 'Observers and model hooks',
            self::Jobs => 'Jobs',
            self::Dispatches => 'Dispatches',
            self::Mailables => 'Mailables',
            self::Notifications => 'Notifications',
            self::Schedule => 'Schedule',
            self::Routes => 'Routes',
            self::Scope => 'Scan scope',
        };
    }

    /** The page, relative to `docs/`. */
    public function page(): string
    {
        return "reference/detection/{$this->value}.md";
    }
}
