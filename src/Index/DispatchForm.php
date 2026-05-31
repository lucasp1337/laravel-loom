<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The syntactic shape of a dispatch site, recorded on
 * `_dispatch_sites[].form`. Internal to dispatch resolution — not emitted
 * in the public schema. The cross-link pass and the per-target scanners read
 * `form` to disambiguate `kind` (e.g. `DISPATCHABLE` is ambiguous until
 * resolved to event or job).
 */
enum DispatchForm: string
{
    case HELPER = 'helper';
    case FACADE = 'facade';
    case JOB_HELPER = 'job_helper';
    case DISPATCHABLE = 'dispatchable';
    case MAIL_FACADE = 'mail_facade';
    case MAIL_CHAIN = 'mail_chain';
    case NOTIFY_METHOD = 'notify_method';
    case NOTIFICATION_FACADE = 'notification_facade';
    case NOTIFICATION_CHAIN = 'notification_chain';
}
