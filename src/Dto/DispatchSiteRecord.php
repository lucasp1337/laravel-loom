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
    ) {
    }
}
