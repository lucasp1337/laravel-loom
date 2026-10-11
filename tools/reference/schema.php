<?php

declare(strict_types=1);

/**
 * Hand-written layer for docs/reference/schema.md. Everything else on that
 * page is generated from schema/loom-index.schema.json; a section without an
 * entry here fails the generator.
 */
return [
    // One line per top-level section, shown in the overview table.
    'sections' => [
        'events' => 'Event classes, where each is fired and which listeners handle it.',
        'model_events' => 'Eloquent model events with the observers and `Event::listen` calls that handle them.',
        'listeners' => 'Listener classes, the events they handle and what they dispatch.',
        'observers' => 'Observer classes, the model each one observes and the hooks it implements.',
        'jobs' => 'Job classes with their queue settings, where they are dispatched and what they dispatch.',
        'unresolved_dispatches' => 'Dispatch calls whose target could not be resolved statically.',
        'closure_listeners' => 'Closure and arrow-function listener registrations.',
        'scheduled_tasks' => 'Task-scheduler entries.',
        'routes' => 'HTTP routes with their middleware and the dispatches their action makes.',
        'mailables' => 'Mailable classes and where they are sent from.',
        'notifications' => 'Notification classes, their channels and where they are sent from.',
    ],
];
