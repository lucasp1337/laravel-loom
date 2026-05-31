<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;

/**
 * A statically resolved dispatch site. Internal to the cross-link pass —
 * not part of the public schema (`_dispatch_sites` is stripped before the
 * Index is built).
 */
final class DispatchSiteRecord
{
    /**
     * @param  'high'|'medium'|'low'  $confidence
     */
    public function __construct(
        public readonly ?string $classFqcn,
        public readonly ?string $method,
        public readonly string $target,
        public readonly DispatchForm $form,
        public DispatchKinds $provisionalKind,
        public ?string $file,
        public readonly int $line,
        public readonly string $confidence = 'high',
        public readonly DispatchOverrides $overrides = new DispatchOverrides,
        /**
         * Dispatch-time channel filter from Notification::send/sendNow arg 2;
         * null when absent or non-literal.
         *
         * @var list<string>|null
         */
        public readonly ?array $channels = null,
        /**
         * True when the site sits physically inside a closure body. Tagged
         * sites are consumed only by ClosureDispatchAttributionPhase; every
         * other cross-link consumer skips them. Carried on `_dispatch_sites`
         * (stripped before schema validation).
         */
        public readonly bool $inClosure = false,
    ) {
    }
}
