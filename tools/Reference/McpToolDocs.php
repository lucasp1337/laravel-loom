<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Lucasp\Loom\Mcp\Tools\DispatchesFromTool;
use Lucasp\Loom\Mcp\Tools\DispatchSitesForTool;
use Lucasp\Loom\Mcp\Tools\EventsFollowingTool;
use Lucasp\Loom\Mcp\Tools\EventsFromMethodTool;
use Lucasp\Loom\Mcp\Tools\FindOrphansTool;
use Lucasp\Loom\Mcp\Tools\FindUnresolvedDispatchesTool;
use Lucasp\Loom\Mcp\Tools\GetEntityTool;
use Lucasp\Loom\Mcp\Tools\HandlersForTool;
use Lucasp\Loom\Mcp\Tools\ImpactOfChangeTool;
use Lucasp\Loom\Mcp\Tools\ListEntitiesTool;
use Lucasp\Loom\Mcp\Tools\RouteToEventsTool;

/**
 * Hand-written layer for docs/reference/mcp-tools.md: the question each tool
 * answers, notes, and which real calls to show. Every registered tool needs
 * exactly one entry; the generator fails otherwise.
 */
final class McpToolDocs
{
    private const ORDER_EVENT = 'App\\Events\\OrderPlaced';

    private const STORE_METHOD = 'App\\Http\\Controllers\\OrderController::store';

    /** @return list<ToolDoc> tools in page order within each group */
    public static function all(): array
    {
        return [
            new ToolDoc(
                tool: ListEntitiesTool::class,
                group: McpGroup::Lookups,
                answers: 'What did the scan find in one section?',
                summary: 'Lists one section of the index verbatim, with a count. Use it to find a class name before asking about it.',
                example: ['section' => 'jobs'],
                notes: ['`items` are the entries of that section of the [index](schema.md), unchanged.'],
                errors: [['section' => 'widgets']],
            ),
            new ToolDoc(
                tool: GetEntityTool::class,
                group: McpGroup::Lookups,
                answers: 'What is the full record for one class?',
                summary: 'Fetches one class\'s record by kind and fully qualified name.',
                example: ['kind' => 'listener', 'fqcn' => 'App\\Listeners\\SendReceipt'],
                notes: ['`entity` is the raw index entry, the same one `list-entities` and `index.json` carry.'],
                errors: [
                    ['kind' => 'job', 'fqcn' => 'App\\Nope'],
                    ['kind' => 'route', 'fqcn' => 'x'],
                ],
            ),
            new ToolDoc(
                tool: DispatchSitesForTool::class,
                group: McpGroup::Edges,
                answers: 'Where is this event fired?',
                summary: 'Lists every place that dispatches the event: the file, the line and the method that contains the call.',
                example: ['event_fqcn' => self::ORDER_EVENT],
                notes: ['An event nothing fires returns `count` `0` and an empty list, not an error.'],
                errors: [['event_fqcn' => '']],
            ),
            new ToolDoc(
                tool: HandlersForTool::class,
                group: McpGroup::Edges,
                answers: 'Who handles this event?',
                summary: 'Lists the listeners and the closure listeners bound to an event.',
                example: ['event_fqcn' => 'App\\Events\\ReceiptSent'],
                notes: [
                    '`count` is listeners plus closure listeners.',
                    'A closure listener has no class name, so it is identified by `file` and `line`.',
                ],
                errors: [['event_fqcn' => '']],
            ),
            new ToolDoc(
                tool: DispatchesFromTool::class,
                group: McpGroup::Edges,
                answers: 'What does this method fire directly?',
                summary: 'Lists the events and jobs one method dispatches, with the file and line of each call.',
                example: ['method_fqcn' => self::STORE_METHOD],
                notes: ['Only routes resolve to an exact method. For a listener, observer or job the answer covers the whole class.'],
                errors: [['method_fqcn' => '']],
            ),
            new ToolDoc(
                tool: EventsFollowingTool::class,
                group: McpGroup::Chains,
                answers: 'What does this event set off, transitively?',
                summary: 'Follows an event to its handlers, what they dispatch, and the handlers of those events, down to `depth` hops.',
                example: ['event_fqcn' => self::ORDER_EVENT],
                notes: [
                    'Each edge is one handler of one event.',
                    'An event that is not in the index returns an empty `edges` list, not an error.',
                    'See [Chain output](#chain-output) for `truncated`, `cycles` and `events_reached`.',
                ],
            ),
            new ToolDoc(
                tool: EventsFromMethodTool::class,
                group: McpGroup::Chains,
                answers: 'What does this method set off, transitively?',
                summary: 'Starts from a method: what it dispatches, then the chain that follows each dispatched event.',
                example: ['method_fqcn' => self::STORE_METHOD, 'depth' => 1],
                notes: [
                    '`dispatches` is what `dispatches-from` returns; `chains` holds one `events-following` result per dispatched event.',
                    'This call used `depth` `1`, so `truncated` is `true`: the chain stops before the handlers of `ReceiptSent`.',
                    'An unknown method returns empty `dispatches` and `chains`.',
                    'Method granularity is exact only for routes; a listener, observer or job resolves at class level.',
                ],
            ),
            new ToolDoc(
                tool: RouteToEventsTool::class,
                group: McpGroup::Chains,
                answers: 'What does this HTTP route set off?',
                summary: 'Resolves a route to its controller method, then behaves like `events-from-method`. The verb is case-insensitive; the URI is matched exactly, with or without a leading slash.',
                example: ['method' => 'POST', 'uri' => '/orders', 'depth' => 1],
                notes: [
                    '`chain` holds what `events-from-method` returns.',
                    '`note` is always present: `null` when `chain` resolved, a sentence when it did not.',
                ],
                variants: [
                    new CallVariant('A closure route has no controller method to follow, so `chain` is `null` and `note` says why', ['method' => 'GET', 'uri' => '/health']),
                ],
                errors: [['method' => 'DELETE', 'uri' => 'nope']],
            ),
            new ToolDoc(
                tool: ImpactOfChangeTool::class,
                group: McpGroup::Analysis,
                answers: 'What does removing or renaming this class affect?',
                summary: 'Reports what removing or renaming an event, listener or job touches. `change` only changes the wording of `notes`.',
                example: ['fqcn' => 'App\\Listeners\\SendReceipt'],
                notes: [
                    'For a listener, `handles` are the events it handles and `would_orphan_events` are those whose only handler it is. A job has the same keys, with empty `handles`.',
                    'For an event, each `handlers` entry is `{ handler, handler_kind }`, named like a chain edge.',
                    '`notes` call out what static analysis cannot determine, such as dynamic dispatches.',
                ],
                variants: [
                    new CallVariant('For an event the report lists who fires it, who handles it, and the chain downstream', ['fqcn' => self::ORDER_EVENT]),
                    new CallVariant('A class Loom does not know gets `kind` `unknown` and a note instead of a chain', ['fqcn' => 'App\\Nope']),
                ],
                errors: [['fqcn' => self::ORDER_EVENT, 'change' => 'explode']],
            ),
            new ToolDoc(
                tool: FindOrphansTool::class,
                group: McpGroup::Analysis,
                answers: 'Which events and listeners are dead weight?',
                summary: 'Lists events that nothing fires and nothing handles, and listeners that handle no event.',
                example: [],
                notes: ['An event fired only from a dispatch Loom could not resolve can look orphaned. Cross-check with `find-unresolved-dispatches`.'],
            ),
            new ToolDoc(
                tool: FindUnresolvedDispatchesTool::class,
                group: McpGroup::Analysis,
                answers: 'Which dispatches could Loom not resolve?',
                summary: 'Lists every dispatch Loom could not trace to a class, with the raw expression and a reason code.',
                example: [],
                notes: ['The `reason` values and their fixes are in [Why was my code missed](../guides/why-was-my-code-missed.md#a-dispatch-shows-up-as-unresolved).'],
            ),
        ];
    }

    /** @return list<ToolDoc> */
    public static function inGroup(McpGroup $group): array
    {
        return array_values(array_filter(self::all(), static fn (ToolDoc $doc): bool => $doc->group === $group));
    }
}
