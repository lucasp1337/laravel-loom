<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\WordCount;

/**
 * Word budgets so the docs cannot silently regrow. Behaviour belongs in tests
 * and docblocks; a page that outgrows its budget needs cutting, not a bigger
 * number. A new page must be given a budget here, which is the point.
 *
 * Words are whitespace-separated tokens with a letter or digit (WordCount).
 * The generated reference pages carry their own budgets in
 * GeneratedPageBudgetTest; they still count toward the total.
 */
const DOCS_TOTAL_BUDGET = 19300;

/**
 * @return array<string, int|null> page => budget, null when budgeted elsewhere
 */
function docsPageBudgets(): array
{
    return [
        'index.md' => 70,
        'getting-started.md' => 500,
        'concepts/what-loom-sees.md' => 690,
        'concepts/the-index.md' => 450,
        'guides/browse-the-ui.md' => 1020,
        'guides/ask-an-agent.md' => 1170,
        'guides/use-with-boost.md' => 280,
        'guides/gate-your-ci.md' => 630,
        'guides/why-was-my-code-missed.md' => 1310,
        'reference/commands.md' => 610,
        'reference/check-rules-and-formats.md' => 720,
        'reference/action.md' => 530,
        'reference/php-api.md' => 600,
        'reference/what-loom-detects.md' => 930,
        'reference/schema.md' => null,
        'reference/mcp-tools.md' => null,
        'reference/scan-config.md' => null,
        'reference/ui-config.md' => null,
        'contributing/architecture.md' => 760,
        'contributing/add-a-scanner.md' => 270,
        'contributing/class-hierarchy.md' => 310,
        'contributing/adr/README.md' => 190,
        'contributing/adr/0001-class-hierarchy-resolver.md' => 320,
        'contributing/adr/0002-schedule-scanner.md' => 440,
        'contributing/adr/0003-mailables-notifications.md' => 460,
        'contributing/adr/0004-sub-minute-frequencies.md' => 230,
        'contributing/adr/0005-index-read-model.md' => 290,
        'contributing/adr/0006-benchmark-suite.md' => 190,
        'contributing/adr/0007-scanners-not-an-extension-point.md' => 300,
    ];
}

/**
 * @return array<string, int> page => words
 */
function docsPageWords(): array
{
    $root = dirname(__DIR__, 2).'/docs';
    $words = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
            $words[substr($file->getPathname(), strlen($root) + 1)] = WordCount::of((string) file_get_contents($file->getPathname()));
        }
    }

    ksort($words);

    return $words;
}

it('gives every docs page a budget', function (): void {
    $unbudgeted = array_values(array_diff(array_keys(docsPageWords()), array_keys(docsPageBudgets())));
    $stale = array_values(array_diff(array_keys(docsPageBudgets()), array_keys(docsPageWords())));

    expect($unbudgeted)->toBe([], 'Add a budget in WordBudgetTest for a new page.')
        ->and($stale)->toBe([], 'Remove the budget of a deleted page.');
});

it('keeps each page within its word budget', function (): void {
    $over = [];
    foreach (docsPageWords() as $page => $words) {
        $budget = docsPageBudgets()[$page] ?? null;
        if ($budget !== null && $words > $budget) {
            $over[] = "{$page}: {$words} words, budget {$budget}";
        }
    }

    expect($over)->toBe([]);
});

it('keeps the docs within the total word budget', function (): void {
    expect(array_sum(docsPageWords()))->toBeLessThanOrEqual(DOCS_TOTAL_BUDGET);
});
