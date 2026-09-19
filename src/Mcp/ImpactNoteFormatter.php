<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp;

use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\Dto\ImpactReport;
use Lucasp\Loom\Query\ImpactNote;

/**
 * Renders {@see ImpactNote} codes as the prose the MCP tool has always returned.
 */
final class ImpactNoteFormatter
{
    public static function render(ImpactNote $note, ImpactReport $report): string
    {
        $handlers = self::plural(count($report->handlers), 'handler');
        $sites = self::plural(count($report->dispatchers), 'dispatch site');

        return match ($note) {
            ImpactNote::RENAME_TOUCHES_ALL => "Renaming this event requires updating all {$sites} and {$handlers} below.",
            ImpactNote::REMOVE_ORPHANS_HANDLERS => "Removing this event orphans its {$handlers}; its {$sites} would dispatch a missing class.",
            ImpactNote::DYNAMIC_DISPATCH_BLIND_SPOT => 'Dynamic dispatches (event($var), string class names) are not statically resolvable and may not appear here.',
            ImpactNote::WOULD_ORPHAN_EVENTS => ($report->kind === ChangeKind::REMOVE ? 'Removing' : 'Renaming')
                .' this class would leave '.self::plural(count($report->wouldOrphanEvents), 'event')
                .' with no remaining handler: '.implode(', ', $report->wouldOrphanEvents).'.',
            ImpactNote::NO_ORPHANS => 'No handled event would be left without a handler by this change.',
            ImpactNote::DOWNSTREAM_NOT_EXPANDED => 'Downstream dispatches from this class are listed; their own chains are not expanded here.',
            ImpactNote::UNKNOWN_FQCN => 'No event, listener, or job in the index matches this FQCN. It may be unscanned, dynamically referenced, or misspelled.',
        };
    }

    private static function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
