<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Index\Field;

/**
 * A source location that dispatches an event, sends a mailable, or notifies a
 * notification. Populated on `events[*].dispatched_from`, `jobs[*].dispatched_from`,
 * `mailables[*].sent_from`, and `notifications[*].notified_from`.
 *
 * @api
 */
final readonly class DispatchSite
{
    /** @param  list<string>|null  $channels */
    public function __construct(
        public string $file,
        public int $line,
        public string $method,
        public ?DispatchMode $mode,
        public ?DispatchOverrides $overrides,
        public ?array $channels,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $overrides = Hydrate::nullableArray($data, Field::OVERRIDES);
        $channels = Hydrate::stringList($data, Field::CHANNELS);
        $mode = Hydrate::nullableString($data, Field::MODE);

        return new self(
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            method: Hydrate::string($data, Field::METHOD),
            mode: $mode === null ? null : DispatchMode::from($mode),
            overrides: $overrides === null ? null : DispatchOverrides::fromArray($overrides),
            channels: $channels === [] ? null : $channels,
        );
    }
}
