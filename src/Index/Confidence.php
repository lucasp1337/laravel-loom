<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Resolution confidence emitted on `dispatches[*].confidence` and carried on
 * dispatch sites: how sure the scanner is that a dispatch target was resolved
 * correctly.
 *
 * @api
 */
enum Confidence: string implements Summarized
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function summary(): string
    {
        return match ($this) {
            self::HIGH => 'the target is resolved in the handler method itself.',
            self::MEDIUM => 'the call sits in a helper method the handler reaches (see `via`).',
            self::LOW => 'reserved; not emitted today.',
        };
    }
}
