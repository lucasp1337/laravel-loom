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
        'README.md' => 490,
        'docs/concepts/the-index.md' => 270,
        'docs/concepts/what-loom-sees.md' => 220,
        'docs/getting-started.md' => 250,
        'docs/guides/ask-an-agent.md' => 330,
        'docs/guides/browse-the-ui.md' => 500,
        'docs/guides/gate-your-ci.md' => 250,
        'docs/guides/use-with-boost.md' => 180,
        'docs/guides/why-was-my-code-missed.md' => 230,
        'docs/index.md' => 250,
        'docs/recipes/event-chain.md' => 70,
        'docs/recipes/job-triggers.md' => 80,
        'docs/recipes/new-unresolved-dispatches.md' => 80,
        'docs/recipes/routes-without-auth.md' => 60,
        'docs/reference/action.md' => 470,
        'docs/reference/check-rules-and-formats.md' => 540,
        'docs/reference/commands.md' => 320,
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
        'docs/reference/mcp-tools.md' => 240,
        'docs/reference/php-api.md' => 70,
        'docs/reference/scan-config.md' => 360,
        'docs/reference/schema.md' => 480,
        'docs/reference/ui-config.md' => 150,
    ];
}
