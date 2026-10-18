<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\MetaField;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Tools\Guards\Fence;
use Lucasp\Loom\Tools\Guards\HandWrittenPage;
use Lucasp\Loom\Tools\Guards\HandWrittenPages;
use Lucasp\Loom\Tools\Guards\Heading;
use Lucasp\Loom\Tools\Guards\SymbolLint;
use Lucasp\Loom\Tools\Guards\WordBudget;

/**
 * Guards on the hand-written layer of the consumer docs: what is left of a
 * page once its generated regions are emptied.
 */
function guardedPages(): array
{
    return HandWrittenPages::all(dirname(__DIR__, 2));
}

it('names only commands, options, config keys, reasons, MCP tools and classes the code has', function (): void {
    $lint = new SymbolLint(dirname(__DIR__, 2));

    expect(collect(guardedPages())->flatMap(static fn (HandWrittenPage $page): array => $lint->problems($page))->all())->toBe([]);
});

it('flags names the code does not have', function (): void {
    $lint = new SymbolLint(dirname(__DIR__, 2));
    $page = new HandWrittenPage('docs/guides/x.md', implode("\n", [
        '# X',
        '',
        'Run `loom:scna`, then `loom:check --strcit` and `loom:scan --path=app`.',
        'Set `scan.pathz` or `scan.*`; read `dynamic_classname` and `dynamic_class_name`.',
        'Ask `events-follwing` or `events-following`; fail on `orphan-events`.',
        'Load with `IndexLoader`, not `IndexBuilder` or `Lucasp\Loom\Console\ScanCommand`.',
        '',
        '```bash',
        'php artisan loom:diff old.json new.json --fromat=json',
        '```',
    ]));

    expect($lint->problems($page))->toBe([
        'docs/guides/x.md: unknown command `loom:scna`',
        'docs/guides/x.md: `loom:check` has no option `--strcit`',
        'docs/guides/x.md: unknown config key `scan.pathz`',
        'docs/guides/x.md: unknown unresolved reason `dynamic_classname`',
        'docs/guides/x.md: unknown MCP tool `events-follwing`',
        'docs/guides/x.md: `IndexBuilder` is an internal class, not an `@api` one',
        'docs/guides/x.md: `Lucasp\Loom\Console\ScanCommand` is not an `@api` class',
        'docs/guides/x.md: `loom:diff` has no option `--fromat`',
    ]);
});

it('runs or marks every php fence', function (): void {
    $unmarked = collect(guardedPages())->flatMap(static fn (HandWrittenPage $page): array => collect($page->fences())
        ->filter(static fn (Fence $fence): bool => $fence->language === 'php' && ! $fence->illustrative)
        ->map(static fn (Fence $fence): string => "{$page->path}:{$fence->line}")
        ->all());

    expect($unmarked->all())->toBe([], 'Show the code from docs/examples/ or docs/snippets/, or put '.Fence::ILLUSTRATIVE.' above the fence.');
});

it('pastes no index or command JSON by hand', function (): void {
    $keys = collect([...Sections::cases(), ...MetaField::cases(), ...Field::cases()])->map(static fn (BackedEnum $case): string => '"'.$case->value.'":');
    $pasted = collect(guardedPages())->flatMap(static fn (HandWrittenPage $page): array => collect($page->fences())
        ->filter(static fn (Fence $fence): bool => $fence->language === 'json' && $keys->contains(static fn (string $key): bool => str_contains($fence->body, $key)))
        ->map(static fn (Fence $fence): string => "{$page->path}:{$fence->line}")
        ->all());

    expect($pasted->all())->toBe([], 'Generate it: add an OutputSample and place its region.');
});

it('keeps every page within its word budget', function (): void {
    $counts = collect(guardedPages())->mapWithKeys(static fn (HandWrittenPage $page): array => [$page->path => $page->words()]);

    $problems = $counts->map(static fn (int $words, string $path): ?string => match (true) {
        ! isset(WordBudget::PAGES[$path]) => "{$path}: no budget ({$words} words)",
        $words > WordBudget::PAGES[$path] => "{$path}: {$words} words, budget ".WordBudget::PAGES[$path],
        $words < WordBudget::PAGES[$path] - WordBudget::SLACK => "{$path}: {$words} words, lower the budget from ".WordBudget::PAGES[$path],
        default => null,
    })->filter()->values();
    $stale = collect(WordBudget::PAGES)->keys()->diff($counts->keys())->map(static fn (string $path): string => "{$path}: budget for a missing page");

    expect([...$problems, ...$stale])->toBe([], 'Hand-written words: '.$counts->sum().' of '.array_sum(WordBudget::PAGES));
});

it('finds code first only right after the title or a one-line lead', function (string $content, bool $opens): void {
    expect((new HandWrittenPage('docs/guides/x.md', $content))->opensWithCode())->toBe($opens);
})->with([
    'fence after the title' => ["# X\n\n```bash\nx\n```\n", true],
    'fence after a one-line lead' => ["# X\n\nLead.\n\n```bash\nx\n```\n", true],
    'generated fence after a lead' => ["# X\n\nLead.\n\n<!-- generated:output:scan -->\n<!-- note -->\n\n```text\nx\n```\n<!-- /generated:output:scan -->\n", true],
    'collapsible example' => ["# X\n\n??? example \"Y\"\n\n    ```php\n    x\n    ```\n", true],
    'two-line lead' => ["# X\n\nLead one\nline two.\n\n```bash\nx\n```\n", false],
    'heading before the code' => ["# X\n\nLead.\n\n## Y\n\n```bash\nx\n```\n", false],
    'no code' => ["# X\n\nLead.\n", false],
]);

it('follows the outline of its page kind', function (): void {
    $problems = collect(guardedPages())->filter(static fn (HandWrittenPage $page): bool => $page->kind() !== null)->flatMap(static function (HandWrittenPage $page): array {
        $outline = $page->kind()->outline();
        $level = static fn (Heading $heading): int => $heading->level;
        $rendered = $page->headings(generated: true);
        $levels = array_map($level, $rendered);
        $problems = [];

        if (! str_starts_with($page->markdown, '# ') || count(array_keys($levels, 1, true)) !== 1) {
            $problems[] = 'must open with its only H1';
        }
        foreach (array_slice($levels, 1) as $i => $next) {
            if ($next > $levels[$i] + 1) {
                $problems[] = "skips from H{$levels[$i]} to H{$next} at \"{$rendered[$i + 1]->text}\"";
            }
        }
        if (max(array_map($level, $page->headings()) ?: [1]) > $outline->maxDepth) {
            $problems[] = "goes deeper than H{$outline->maxDepth}";
        }
        if ($outline->lead && ($page->lead() === null || str_starts_with($page->lead(), '#'))) {
            $problems[] = 'needs a lead paragraph under the H1';
        }
        if ($outline->code && ! $page->opensWithCode()) {
            $problems[] = 'must open with code, after at most a one-line lead';
        }
        if ($outline->generated && ! str_contains($page->markdown, '<!-- generated:')) {
            $problems[] = 'needs a generated region';
        }

        return array_map(static fn (string $problem): string => "{$page->path} ({$page->kind()->value}): {$problem}", $problems);
    });

    expect($problems->values()->all())->toBe([]);
});
