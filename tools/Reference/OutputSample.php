<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/**
 * Command output and index excerpts shown in the guides, each produced by
 * running Loom on the docs fixture app. The value is the region key:
 * `<!-- generated:output:KEY -->`.
 */
enum OutputSample: string
{
    case Scan = 'scan';
    case ScanVerbose = 'scan-verbose';
    case ShowMissing = 'show-missing';
    case ShowEvent = 'show-event';
    case McpNoScan = 'mcp-no-scan';
    case McpEventsFollowing = 'mcp-events-following';
    case McpUnresolved = 'mcp-unresolved';
    case AgentImpact = 'agent-impact';
    case IndexShape = 'index-shape';
    case IndexRoute = 'index-route';
    case IndexEvent = 'index-event';
    case IndexListener = 'index-listener';
    case IndexJob = 'index-job';
    case Check = 'check';
    case CheckBaseline = 'check-baseline';
    case CheckNewUnresolved = 'check-new-unresolved';
    case CheckJson = 'check-json';
    case CheckSkipped = 'check-skipped';
    case CheckMarkdown = 'check-markdown';
    case Diff = 'diff';
    case DiffJson = 'diff-json';
    case DiffMarkdown = 'diff-markdown';

    /** Language of the fence the sample is shown in. */
    public function fence(): string
    {
        return match ($this) {
            // Index entries and the JSON formats.
            self::ShowEvent, self::McpEventsFollowing, self::McpUnresolved, self::AgentImpact, self::IndexShape, self::IndexRoute,
            self::IndexEvent, self::IndexListener, self::IndexJob, self::CheckJson, self::CheckSkipped, self::DiffJson => 'json',
            // The markdown formats, shown as source.
            self::CheckMarkdown, self::DiffMarkdown => 'markdown',
            // Plain console output.
            default => 'text',
        };
    }
}
