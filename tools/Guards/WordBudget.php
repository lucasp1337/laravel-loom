<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

/**
 * Most hand-written words each consumer page may have, counted outside code
 * fences and generated regions. Budgets only go down: a page that falls well
 * under its budget fails until the budget is lowered to match.
 */
final class WordBudget
{
    /** How far under budget a page may fall before the budget must be lowered. */
    public const SLACK = 50;

    /** @var array<string, int> page => words */
    public const PAGES = [
        'README.md' => 570,
        'docs/concepts/the-index.md' => 520,
        'docs/concepts/what-loom-sees.md' => 860,
        'docs/getting-started.md' => 550,
        'docs/guides/ask-an-agent.md' => 1510,
        'docs/guides/browse-the-ui.md' => 1560,
        'docs/guides/gate-your-ci.md' => 870,
        'docs/guides/use-with-boost.md' => 310,
        'docs/guides/why-was-my-code-missed.md' => 230,
        'docs/index.md' => 240,
        'docs/reference/action.md' => 620,
        'docs/reference/check-rules-and-formats.md' => 900,
        'docs/reference/commands.md' => 540,
        'docs/reference/detection/closure-listeners.md' => 20,
        'docs/reference/detection/dispatches.md' => 20,
        'docs/reference/detection/events.md' => 20,
        'docs/reference/detection/index.md' => 110,
        'docs/reference/detection/jobs.md' => 20,
        'docs/reference/detection/listeners.md' => 20,
        'docs/reference/detection/mailables.md' => 20,
        'docs/reference/detection/notifications.md' => 20,
        'docs/reference/detection/observers.md' => 20,
        'docs/reference/detection/routes.md' => 20,
        'docs/reference/detection/schedule.md' => 20,
        'docs/reference/detection/scope.md' => 20,
        'docs/reference/mcp-tools.md' => 320,
        'docs/reference/php-api.md' => 70,
        'docs/reference/scan-config.md' => 790,
        'docs/reference/schema.md' => 680,
        'docs/reference/ui-config.md' => 220,
    ];
}
