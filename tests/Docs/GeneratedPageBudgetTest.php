<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\WordCount;

/**
 * Word budgets for the generated reference pages. A page that outgrows its
 * budget is a signal to cut prose or tighten the source descriptions, not to
 * raise the number without a reason.
 */
it('keeps the generated reference pages within their word budget', function (): void {
    $budgets = [
        'docs/reference/schema.md' => 2500,
        'docs/reference/mcp-tools.md' => 1200,
        'docs/reference/scan-config.md' => 850,
        'docs/reference/ui-config.md' => 400,
    ];

    $over = [];
    foreach ($budgets as $page => $budget) {
        $words = WordCount::of((string) file_get_contents(dirname(__DIR__, 2).'/'.$page));
        if ($words > $budget) {
            $over[] = "{$page}: {$words} words, budget {$budget}";
        }
    }

    expect($over)->toBe([]);
});
