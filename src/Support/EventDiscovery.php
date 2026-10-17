<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The verdict on listener discovery: whether it runs, and the app-relative
 * directories it reads (`app/Listeners` unless the app set others).
 *
 * @internal
 */
final readonly class EventDiscovery
{
    public const string DEFAULT_DIRECTORY = 'app/Listeners';

    /**
     * @param  list<string>|null  $directories  null for the default directory
     */
    public function __construct(
        public EventDiscoveryStatus $status,
        public ?array $directories = null,
    ) {}
}
