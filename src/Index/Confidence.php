<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Resolution confidence emitted on `dispatches[*].confidence` and carried on
 * dispatch sites: how sure the scanner is that a dispatch target was resolved
 * correctly.
 */
enum Confidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
}
