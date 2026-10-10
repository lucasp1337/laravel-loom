<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Scanners\Discovery\ClassPrimitiveDiscovery;
use Lucasp\Loom\Scanners\Discovery\EventClassSpec;
use Lucasp\Loom\Scanners\Discovery\JobClassSpec;
use Lucasp\Loom\Scanners\Discovery\MailableClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\Psr4ClassLocator;

/**
 * Build a throwaway app tree from `relative path => php body` (namespace and
 * class are derived from the path under app/).
 *
 * @param  array<string, string>  $files
 */
function discoveryApp(array $files): string
{
    $root = sys_get_temp_dir().'/loom-discovery-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $source) {
        File::ensureDirectoryExists(dirname("$root/$path"));
        File::put("$root/$path", "<?php\n\n$source\n");
    }

    return $root;
}

function discoverWith(object $spec, string $root): array
{
    return (new ClassPrimitiveDiscovery(new AstWalker, new Psr4ClassLocator))->discover($root, $spec);
}

it('walks the convention directory and sorts entries by FQCN', function () {
    $root = discoveryApp([
        'app/Events/Zeta.php' => "namespace App\\Events;\nclass Zeta {}",
        'app/Events/Alpha.php' => "namespace App\\Events;\nclass Alpha {}",
        'app/Services/NotAnEvent.php' => "namespace App\\Services;\nclass NotAnEvent {}",
    ]);

    $entries = discoverWith(new EventClassSpec, $root);

    expect(array_map(fn (EventEntry $e): string => $e->fqcn, $entries))
        ->toBe(['App\\Events\\Alpha', 'App\\Events\\Zeta']);
    expect($entries[0]->file)->toBe('app/Events/Alpha.php');
    expect($entries[0]->line)->toBe(4);

    File::deleteDirectory($root);
});

it('adds unambiguous dispatch targets located outside the convention directory', function () {
    $root = discoveryApp([
        'app/Domain/Shipped.php' => "namespace App\\Domain;\nclass Shipped {}",
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Domain\\Shipped;\nclass Controller {\n    public function go(): void { event(new Shipped); }\n}",
    ]);

    $fqcns = array_map(fn (EventEntry $e): string => $e->fqcn, discoverWith(new EventClassSpec, $root));

    expect($fqcns)->toBe(['App\\Domain\\Shipped']);

    File::deleteDirectory($root);
});

it('rejects an ambiguous Dispatchable target outside the event directory', function () {
    $root = discoveryApp([
        'app/Domain/Thing.php' => "namespace App\\Domain;\nclass Thing {}",
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Domain\\Thing;\nclass Controller {\n    public function go(): void { Thing::dispatch(); }\n}",
    ]);

    expect(discoverWith(new EventClassSpec, $root))->toBe([]);

    File::deleteDirectory($root);
});

it('keeps an ambiguous target as a job only when queued or under Jobs', function () {
    $root = discoveryApp([
        'app/Domain/Queued.php' => "namespace App\\Domain;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass Queued implements ShouldQueue {}",
        'app/Domain/Plain.php' => "namespace App\\Domain;\nclass Plain {}",
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Domain\\Plain;\nuse App\\Domain\\Queued;\nclass Controller {\n    public function go(): void { Queued::dispatch(); Plain::dispatch(); }\n}",
    ]);

    $entries = discoverWith(new JobClassSpec, $root);

    expect(array_map(fn (JobEntry $e): string => $e->fqcn, $entries))->toBe(['App\\Domain\\Queued']);
    expect($entries[0]->queued)->toBeTrue();

    File::deleteDirectory($root);
});

it('lets a proven dispatch form win over an ambiguous one for the same target', function () {
    $root = discoveryApp([
        'app/Domain/Plain.php' => "namespace App\\Domain;\nclass Plain {}",
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Domain\\Plain;\nclass Controller {\n    public function a(): void { Plain::dispatch(); }\n    public function b(): void { dispatch(new Plain); }\n}",
    ]);

    $fqcns = array_map(fn (JobEntry $e): string => $e->fqcn, discoverWith(new JobClassSpec, $root));

    expect($fqcns)->toBe(['App\\Domain\\Plain']);

    File::deleteDirectory($root);
});

it('skips dispatch targets that do not resolve to a file', function () {
    $root = discoveryApp([
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Mail\\Ghost;\nclass Controller {\n    public function go(): void { Mail::send(new Ghost); }\n}",
    ]);

    expect(discoverWith(new MailableClassSpec, $root))->toBe([]);

    File::deleteDirectory($root);
});

it('drops queue config for non-queued entries', function () {
    $root = discoveryApp([
        'app/Mail/Plain.php' => "namespace App\\Mail;\nclass Plain { public \$queue = 'x'; }",
    ]);

    $entries = discoverWith(new MailableClassSpec, $root);

    expect($entries[0])->toBeInstanceOf(MailableEntry::class);
    expect($entries[0]->queued)->toBeFalse();
    expect($entries[0]->queueConfig)->toBeNull();

    File::deleteDirectory($root);
});
