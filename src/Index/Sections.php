<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Top-level section names emitted by the index.
 */
enum Sections: string
{
    case EVENTS = 'events';
    case LISTENERS = 'listeners';
    case OBSERVERS = 'observers';
    case MODEL_EVENTS = 'model_events';
    case JOBS = 'jobs';
    case UNRESOLVED_DISPATCHES = 'unresolved_dispatches';
    case CLOSURE_LISTENERS = 'closure_listeners';
    case SCHEDULED = 'scheduled';
    case MAILABLES = 'mailables';
    case NOTIFICATIONS = 'notifications';
    case ROUTES = 'routes';
}
