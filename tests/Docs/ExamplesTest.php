<?php

declare(strict_types=1);

/*
 * The example corpus: `docs/examples/<primitive>/<case>.php`, one page per
 * primitive under `docs/reference/detection/`. Each case is scanned on top of
 * `tests/Fixtures/examples-app` (which holds OrderPlaced, ProcessOrder,
 * OrderReceipt, InvoicePaid, Order and User) and every marker must hold.
 *
 * Case format:
 *
 *     <?php
 *
 *     // Title shown on the page
 *
 *     // app/Listeners/SendReceipt.php      <- each `// path.php` line opens an app file
 *     namespace App\Listeners;
 *     ...
 *
 * Markers are trailing comments; each states what the index holds for its line:
 *
 *     → section[key].path value   an entry of `section` (selected by key, else
 *                                 the one declared on this line) reaches `value`
 *                                 through `path`. Lists are stepped through; a
 *                                 JSON value is compared as JSON; a short class
 *                                 name matches its FQCN. A dispatch site or
 *                                 dispatch on the path must sit on this line.
 *                                 No value: the path reaches something.
 *     ✗ reason                    an unresolved dispatch or route here, with that reason
 *     ✗ not recorded              nothing in the index points at this line
 *
 * Keys: class sections by FQCN, `model_events` by id, `closure_listeners` by
 * event, `scheduled_tasks` by target, `routes` by URI.
 */

use Illuminate\Filesystem\Filesystem;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;
use Lucasp\Loom\Tools\Examples\ExampleCase;
use Lucasp\Loom\Tools\Examples\ExampleCorpus;
use Lucasp\Loom\Tools\Examples\ExampleFile;
use Lucasp\Loom\Tools\Examples\Marker;
use Lucasp\Loom\Tools\Examples\MarkerMatcher;
use Lucasp\Loom\Tools\Examples\Primitive;

/**
 * Scans the stage app with the case's files laid over it.
 *
 * @return array<string, mixed>
 */
function exampleScan(ExampleCase $case): array
{
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/loom-example-'.bin2hex(random_bytes(6));
    $files->copyDirectory(dirname(__DIR__).'/Fixtures/examples-app', $root);
    foreach ($case->files as $file) {
        $files->ensureDirectoryExists(dirname("{$root}/{$file->path}"));
        $files->put("{$root}/{$file->path}", $file->source());
    }

    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, new ScanScope);
    $payload = $builder->build($root, '12.x')->toArray();
    $files->deleteDirectory($root);

    return $payload;
}

function examplesRoot(): string
{
    return dirname(__DIR__, 2);
}

it('holds every marker at its line', function (ExampleCase $case): void {
    $matcher = new MarkerMatcher(exampleScan($case));
    $paths = collect($case->files)->map(fn (ExampleFile $file): string => $file->path)->all();

    $failures = collect($case->markers)
        ->map(fn (Marker $marker): ?string => $matcher->failure($marker))
        ->filter()
        ->merge(collect($matcher->unmarked($case->markers, $paths))->map(fn (string $entry): string => "{$entry}: unresolved without a ✗ marker"))
        ->values()
        ->all();

    expect($case->markers)->not->toBeEmpty()
        ->and($failures)->toBe([]);
})->with(fn (): array => collect(ExampleCorpus::all(examplesRoot()))
    ->mapWithKeys(fn (ExampleCase $case): array => ["{$case->primitive->value}/{$case->slug}" => [$case]])
    ->all());

it('shows every unresolved reason', function (): void {
    $shown = collect(ExampleCorpus::all(examplesRoot()))->flatMap(fn (ExampleCase $case): array => $case->reasons()->all())->unique();
    $reasons = collect(UnresolvedReason::cases())->merge(UnresolvedRouteReason::cases())
        ->map(fn (UnresolvedReason|UnresolvedRouteReason $reason): string => $reason->value);

    expect($reasons->diff($shown)->values()->all())->toBe([]);
});

it('gives every primitive a page and every directory a primitive', function (): void {
    ExampleCorpus::assertKnownDirectories(examplesRoot());

    $nav = (string) file_get_contents(examplesRoot().'/mkdocs.yml');
    foreach (Primitive::cases() as $primitive) {
        expect(ExampleCorpus::for(examplesRoot(), $primitive))->not->toBeEmpty()
            ->and((string) file_get_contents(examplesRoot().'/docs/'.$primitive->page()))->toStartWith("# {$primitive->title()}\n")
            ->and($nav)->toContain("- {$primitive->title()}: {$primitive->page()}");
    }
});
