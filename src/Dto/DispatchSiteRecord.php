<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A statically resolved dispatch site. Internal to the cross-link pass —
 * not part of the public schema (`_dispatch_sites` is stripped before the
 * Index is built).
 */
final class DispatchSiteRecord
{
    /**
     * @param  'helper'|'facade'|'job_helper'|'dispatchable'|'mail_facade'|'mail_chain'|'notify_method'|'notification_facade'|'notification_chain'  $form
     * @param  'event'|'job'|'ambiguous'|'mailable'|'notification'  $provisionalKind
     * @param  'high'|'medium'|'low'  $confidence
     */
    public function __construct(
        public readonly ?string $classFqcn,
        public readonly ?string $method,
        public readonly string $target,
        public readonly string $form,
        public string $provisionalKind,
        public ?string $file,
        public readonly int $line,
        public readonly string $confidence = 'high',
    ) {
    }
}
