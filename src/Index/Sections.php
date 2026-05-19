<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Canonical top-level section names emitted by the index. Single source
 * of truth: `IndexBuilder::build()` and `Index::toArray()` both reference
 * these cases instead of duplicating string literals.
 *
 * String-backed so cases can serialise to the schema-defined JSON keys
 * via `->value`. Use `Sections::EVENTS->value` when indexing into the
 * raw sections array.
 *
 * Internal sections (e.g. `_dispatch_sites`) are not listed here; their
 * keys are scanner-defined and start with `_` by convention.
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
}
