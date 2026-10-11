<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/**
 * The top-level index sections that hold entries, with the one-line meaning
 * shown in the schema overview table. Cases mirror the schema's array
 * properties; the generator fails on a section without a case.
 */
enum SchemaSection: string
{
    case Events = 'events';
    case ModelEvents = 'model_events';
    case Listeners = 'listeners';
    case Observers = 'observers';
    case Jobs = 'jobs';
    case UnresolvedDispatches = 'unresolved_dispatches';
    case ClosureListeners = 'closure_listeners';
    case ScheduledTasks = 'scheduled_tasks';
    case Routes = 'routes';
    case Mailables = 'mailables';
    case Notifications = 'notifications';

    public function description(): string
    {
        return match ($this) {
            self::Events => 'Event classes, where each is fired and which listeners handle it.',
            self::ModelEvents => 'Eloquent model events with the observers and `Event::listen` calls that handle them.',
            self::Listeners => 'Listener classes, the events they handle and what they dispatch.',
            self::Observers => 'Observer classes, the model each one observes and the hooks it implements.',
            self::Jobs => 'Job classes with their queue settings, where they are dispatched and what they dispatch.',
            self::UnresolvedDispatches => 'Dispatch calls whose target could not be resolved statically.',
            self::ClosureListeners => 'Closure and arrow-function listener registrations.',
            self::ScheduledTasks => 'Task-scheduler entries.',
            self::Routes => 'HTTP routes with their middleware and the dispatches their action makes.',
            self::Mailables => 'Mailable classes and where they are sent from.',
            self::Notifications => 'Notification classes, their channels and where they are sent from.',
        };
    }
}
