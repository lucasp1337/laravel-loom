<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * The queue-config settings Loom tracks per queueable class: six scalar properties and `afterCommit`.
 *
 * @internal
 */
final readonly class QueueConfigData
{
    public function __construct(
        public string|int|null $connection,
        public string|int|null $queue,
        public string|int|null $delay,
        public string|int|null $tries,
        public string|int|null $timeout,
        public string|int|null $backoff,
        public ?bool $afterCommit = null,
    ) {}

    /**
     * Build from the settings read off a class, keeping only values of the right type.
     *
     * @param  array<string, string|int|bool|null>  $settings  keyed by property name; `afterCommit` is the only bool
     */
    public static function fromSettings(array $settings): self
    {
        $scalar = static fn (string $key): string|int|null => is_string($settings[$key] ?? null) || is_int($settings[$key] ?? null) ? $settings[$key] : null;

        return new self(
            connection: $scalar('connection'),
            queue: $scalar('queue'),
            delay: $scalar('delay'),
            tries: $scalar('tries'),
            timeout: $scalar('timeout'),
            backoff: $scalar('backoff'),
            afterCommit: is_bool($settings['afterCommit'] ?? null) ? $settings['afterCommit'] : null,
        );
    }
}
