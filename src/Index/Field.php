<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Source of truth for per-entry property names emitted by the index.
 */
enum Field: string
{
    case ID = 'id';
    case FQCN = 'fqcn';
    case KIND = 'kind';
    case FILE = 'file';
    case LINE = 'line';
    case DISPATCHED_FROM = 'dispatched_from';
    case HANDLED_BY = 'handled_by';
    case LISTENER = 'listener';
    case METHOD = 'method';
    case MODEL = 'model';
    case EVENT = 'event';
    case HANDLES = 'handles';
    case REGISTRATION = 'registration';
    case QUEUED = 'queued';
    case DISPATCHES = 'dispatches';
    case OBSERVES = 'observes';
    case HOOKS = 'hooks';
    case QUEUE_CONFIG = 'queue_config';
    case CONNECTION = 'connection';
    case QUEUE = 'queue';
    case DELAY = 'delay';
    case TRIES = 'tries';
    case TIMEOUT = 'timeout';
    case BACKOFF = 'backoff';
    case OVERRIDES = 'overrides';
    case CHANNELS = 'channels';
    case LOCALE = 'locale';
    case MAILER = 'mailer';
    case AFTER_COMMIT = 'after_commit';
    case TARGET = 'target';
    case CONFIDENCE = 'confidence';
    case END_LINE = 'end_line';
    case NAME = 'name';
    case CRON = 'cron';
    case TIMEZONE = 'timezone';
    case WITHOUT_OVERLAPPING = 'without_overlapping';
    case ON_ONE_SERVER = 'on_one_server';
    case RUN_IN_BACKGROUND = 'run_in_background';
    case EVEN_IN_MAINTENANCE_MODE = 'even_in_maintenance_mode';
    case CONSTRAINTS = 'constraints';
    case SENT_FROM = 'sent_from';
    case NOTIFIED_FROM = 'notified_from';
    case CHANNELS_DYNAMIC = 'channels_dynamic';
    case EXPRESSION = 'expression';
    case REASON = 'reason';
    case URI = 'uri';
    case CONTROLLER_FQCN = 'controller_fqcn';
    case CONTROLLER_METHOD = 'controller_method';
    case MIDDLEWARE = 'middleware';
}
