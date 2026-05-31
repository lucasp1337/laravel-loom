<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Dispatch-time chain modifiers captured from a fluent dispatch site, e.g.
 * `dispatch((new Job)->onQueue('high')->delay(60))` or
 * `Job::dispatch(...)->onConnection('redis')`.
 *
 * Every field is optional: a null scalar (or null `$afterCommit`) means the
 * corresponding modifier was absent or its argument was not a static literal.
 * Internal to dispatch resolution; serialized to the optional `overrides`
 * block only when {@see DispatchOverrides::isEmpty()} is false.
 */
final readonly class DispatchOverrides
{
    public function __construct(
        public ?string $locale = null,
        public ?string $mailer = null,
        public ?string $connection = null,
        public ?string $queue = null,
        public ?int $delay = null,
        public ?bool $afterCommit = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->locale === null
            && $this->mailer === null
            && $this->connection === null
            && $this->queue === null
            && $this->delay === null
            && $this->afterCommit === null;
    }

    /**
     * The non-null fields keyed by their emitted schema name, in schema order.
     * Returns `[]` when empty.
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->locale !== null) {
            $out['locale'] = $this->locale;
        }
        if ($this->mailer !== null) {
            $out['mailer'] = $this->mailer;
        }
        if ($this->connection !== null) {
            $out['connection'] = $this->connection;
        }
        if ($this->queue !== null) {
            $out['queue'] = $this->queue;
        }
        if ($this->delay !== null) {
            $out['delay'] = $this->delay;
        }
        if ($this->afterCommit !== null) {
            $out['after_commit'] = $this->afterCommit;
        }

        return $out;
    }
}
