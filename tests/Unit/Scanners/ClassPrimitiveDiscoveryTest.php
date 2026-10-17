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
use Lucasp\Loom\Scanners\Discovery\NotificationClassSpec;
use Lucasp\Loom\Support\Ast\AstWalker;
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
    return (new ClassPrimitiveDiscovery(new AstWalker, new Psr4ClassLocator))->discover($root, $spec)->entries;
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
        'app/Mail/Plain.php' => "namespace App\\Mail;\nclass Plain extends \\Illuminate\\Mail\\Mailable { public \$queue = 'x'; }",
    ]);

    $entries = discoverWith(new MailableClassSpec, $root);

    expect($entries[0])->toBeInstanceOf(MailableEntry::class);
    expect($entries[0]->queued)->toBeFalse();
    expect($entries[0]->queueConfig)->toBeNull();

    File::deleteDirectory($root);
});

it('reports an unlocatable dispatch target as class_not_found, once per site', function () {
    $root = discoveryApp([
        'app/Http/Controller.php' => "namespace App\\Http;\nuse Illuminate\\Auth\\Events\\Registered;\nclass Controller {\n    public function go(): void { event(new Registered); }\n}",
    ]);

    $result = (new ClassPrimitiveDiscovery(new AstWalker, new Psr4ClassLocator))->discover($root, new EventClassSpec);

    expect($result->entries)->toBe([])
        ->and($result->unresolved)->toHaveCount(1)
        ->and($result->unresolved[0]->file)->toBe('app/Http/Controller.php')
        ->and($result->unresolved[0]->line)->toBe(6)
        ->and($result->unresolved[0]->expression)->toBe('Illuminate\\Auth\\Events\\Registered::class')
        ->and($result->unresolved[0]->reason)->toBe('class_not_found');

    File::deleteDirectory($root);
});

it('reports an unlocatable Dispatchable target from the job spec only', function () {
    $root = discoveryApp([
        'app/Http/Controller.php' => "namespace App\\Http;\nuse App\\Gone;\nclass Controller {\n    public function go(): void { Gone::dispatch(); }\n}",
    ]);
    $discovery = new ClassPrimitiveDiscovery(new AstWalker, new Psr4ClassLocator);

    expect($discovery->discover($root, new EventClassSpec)->unresolved)->toBe([])
        ->and($discovery->discover($root, new JobClassSpec)->unresolved)->toHaveCount(1);

    File::deleteDirectory($root);
});

it('lists only mailables and notifications under their directories, not helper classes', function () {
    $root = discoveryApp([
        'app/Mail/Receipt.php' => "namespace App\\Mail;\nuse Illuminate\\Mail\\Mailable;\nclass Receipt extends Mailable {}",
        'app/Mail/Modern.php' => "namespace App\\Mail;\nclass Modern { public function envelope() {} }",
        'app/Mail/MarkdownExtension.php' => "namespace App\\Mail;\nclass MarkdownExtension { public function render() {} }",
        'app/Notifications/Welcome.php' => "namespace App\\Notifications;\nuse Illuminate\\Notifications\\Notification;\nclass Welcome extends Notification {}",
        'app/Notifications/Plain.php' => "namespace App\\Notifications;\nclass Plain { public function via() {} }",
        'app/Notifications/Channels/SlackChannel.php' => "namespace App\\Notifications\\Channels;\nclass SlackChannel { public function send() {} }",
    ]);

    $mail = array_map(fn ($e): string => $e->fqcn, discoverWith(new MailableClassSpec, $root));
    $notes = array_map(fn ($e): string => $e->fqcn, discoverWith(new NotificationClassSpec, $root));

    expect($mail)->toBe(['App\\Mail\\Modern', 'App\\Mail\\Receipt'])
        ->and($notes)->toBe(['App\\Notifications\\Plain', 'App\\Notifications\\Welcome']);

    File::deleteDirectory($root);
});

it('merges queue settings from constructors, parents and traits, and treats ShouldQueue sub-interfaces as queued', function () {
    $root = discoveryApp([
        'app/Jobs/Base.php' => "namespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nabstract class Base implements ShouldQueue { public int \$tries = 5; public \$queue = 'base'; }",
        'app/Jobs/Child.php' => "namespace App\\Jobs;\nclass Child extends Base { public function __construct() { \$this->onQueue('ssh')->onConnection('redis'); } }",
        'app/Jobs/Assigned.php' => "namespace App\\Jobs;\nclass Assigned extends Base { public function __construct() { \$this->delay(30); \$this->tries = 2; } }",
        'app/Jobs/Branched.php' => "namespace App\\Jobs;\nclass Branched extends Base { public function __construct() { if (true) { \$this->onQueue('x'); } } }",
        'app/Jobs/AfterCommit.php' => "namespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit;\nclass AfterCommit implements ShouldQueueAfterCommit {}",
    ]);

    $jobs = collect(discoverWith(new JobClassSpec, $root))->keyBy('fqcn');

    expect($jobs['App\\Jobs\\Child']->queued)->toBeTrue()
        ->and($jobs['App\\Jobs\\Child']->queueConfig->queue)->toBe('ssh')
        ->and($jobs['App\\Jobs\\Child']->queueConfig->connection)->toBe('redis')
        ->and($jobs['App\\Jobs\\Child']->queueConfig->tries)->toBe(5)
        ->and($jobs['App\\Jobs\\Assigned']->queueConfig->delay)->toBe(30)
        ->and($jobs['App\\Jobs\\Assigned']->queueConfig->tries)->toBe(2)
        ->and($jobs['App\\Jobs\\Assigned']->queueConfig->queue)->toBe('base')
        ->and($jobs['App\\Jobs\\Branched']->queueConfig->queue)->toBe('base')
        ->and($jobs['App\\Jobs\\AfterCommit']->queued)->toBeTrue();

    File::deleteDirectory($root);
});
