<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Canonical Laravel contract FQCNs that Loom matches against statically.
 * Centralises the strings so scanners and visitors don't redeclare them.
 *
 * Currently only `ShouldQueue` is needed (job/listener/mailable/
 * notification queue detection). Future contracts (`ShouldBeUnique`,
 * `ShouldBeEncrypted`, `Batchable`, `InteractsWithQueue`) land here.
 */
final class LaravelContracts
{
    public const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';
}
