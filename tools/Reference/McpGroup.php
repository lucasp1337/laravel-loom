<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/** The sections of the MCP tool reference, in page order. */
enum McpGroup: string
{
    case Lookups = 'lookups';
    case Edges = 'edges';
    case Chains = 'chains';
    case Analysis = 'analysis';

    public function title(): string
    {
        return match ($this) {
            self::Lookups => 'Lookups',
            self::Edges => 'Edges',
            self::Chains => 'Chains',
            self::Analysis => 'Analysis',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Lookups => 'Find a class or list a section of the index.',
            self::Edges => 'One hop, no recursion.',
            self::Chains => 'Follow dispatches transitively, down to `depth` hops.',
            self::Analysis => 'Review questions over the whole graph.',
        };
    }
}
