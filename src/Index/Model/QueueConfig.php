<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Illuminate\Support\Arr;
use Lucasp\Loom\Index\Field;

/**
 * Static queue configuration read off a queueable class (job, mailable,
 * notification): `$connection`, `$queue`, `$delay`, `$tries`, `$timeout`,
 * `$backoff` (an int list for `[10, 60]`), and whether the class dispatches after the transaction commits.
 * Each value is the literal property value, or null when absent; `afterCommit`
 * is true or false when the source settles it, null when only the queue
 * connection's config could.
 *
 * @api
 */
final readonly class QueueConfig
{
    public function __construct(
        public string|int|null $connection,
        public string|int|null $queue,
        public string|int|null $delay,
        public string|int|null $tries,
        public string|int|null $timeout,
        /** @var string|int|list<int>|null */
        public string|int|array|null $backoff,
        public ?bool $afterCommit = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: self::scalar($data, Field::CONNECTION),
            queue: self::scalar($data, Field::QUEUE),
            delay: self::scalar($data, Field::DELAY),
            tries: self::scalar($data, Field::TRIES),
            timeout: self::scalar($data, Field::TIMEOUT),
            backoff: self::backoff($data),
            afterCommit: Hydrate::nullableBool($data, Field::AFTER_COMMIT),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string|int|list<int>|null
     */
    private static function backoff(array $data): string|int|array|null
    {
        $value = $data[Field::BACKOFF->value] ?? null;

        return is_array($value) ? array_values(Arr::where($value, static fn (mixed $seconds): bool => is_int($seconds))) : self::scalar($data, Field::BACKOFF);
    }

    /** @param  array<string, mixed>  $data */
    private static function scalar(array $data, Field $field): string|int|null
    {
        $value = $data[$field->value] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }
}
