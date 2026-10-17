<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;
use Lucasp\Loom\Tools\Reference\UnreadForm;
use Lucasp\Loom\Tools\Reference\UnreadForms;

/**
 * Scans one example in a throwaway app and returns the reasons it produced.
 *
 * @return list<string>
 */
function reasonsOf(UnreadForm $form): array
{
    $root = sys_get_temp_dir().'/loom-form-'.bin2hex(random_bytes(6));
    $files = ['app/Forms/Example.php' => $form->context->wrap($form->code, 'Example', $form->importLines())] + $form->files;
    foreach ($files as $path => $source) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0777, true);
        }
        file_put_contents($root.'/'.$path, $source);
    }

    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, new ScanScope);
    $payload = $builder->build($root, '12.x')->toArray();

    (new Filesystem)->deleteDirectory($root);

    return collect($payload['unresolved_dispatches'])->pluck('reason')->all();
}

it('has at least one example for every unresolved reason', function (): void {
    $covered = collect(UnreadForms::all())->map(fn (UnreadForm $form): string => $form->reason->value)->unique();

    expect(collect(UnresolvedReason::cases())->map(fn (UnresolvedReason $reason): string => $reason->value)->diff($covered)->values()->all())->toBe([]);
});

it('keeps the schema description equal to the reason summaries', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/schema/loom-index.schema.json'), true);

    $expected = collect(UnresolvedReason::cases())->map(fn (UnresolvedReason $reason): string => "`{$reason->value}`: {$reason->summary()}")->implode("\n");

    expect($schema['$defs']['unresolvedDispatch']['properties']['reason']['description'])->toBe($expected);
});

it('scans every documented form to exactly its reason', function (UnreadForm $form): void {
    expect(reasonsOf($form))->toBe([$form->reason->value]);
})->with(fn (): array => collect(UnreadForms::all())
    ->mapWithKeys(fn (UnreadForm $form): array => [$form->reason->value.': '.$form->code => [$form]])
    ->all());
