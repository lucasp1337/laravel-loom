<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Lucasp\Loom\Dto\ClassRecord;
use Lucasp\Loom\Dto\JobLocation;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Dto\SourceLocation;
use Lucasp\Loom\Scanners\Discovery\ClassSpec;
use Lucasp\Loom\Scanners\Discovery\DispatchSeed;
use Lucasp\Loom\Scanners\Discovery\EventClassSpec;
use Lucasp\Loom\Scanners\Discovery\JobClassSpec;
use Lucasp\Loom\Scanners\Discovery\MailableClassSpec;
use Lucasp\Loom\Scanners\Discovery\NotificationClassSpec;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * Walk one source snippet with the spec's seed visitors.
 *
 * @return array<string, bool> seeded FQCN => ambiguous
 */
function seedsOf(ClassSpec $spec, string $body): array
{
    $file = sys_get_temp_dir().'/loom-spec-'.bin2hex(random_bytes(6)).'.php';
    File::put($file, "<?php\n\n$body\n");

    $visitors = $spec->seedVisitors();
    (new AstWalker)->walk($file, $visitors);
    File::delete($file);

    $seeds = [];
    foreach ($spec->seedsFrom($visitors) as $seed) {
        expect($seed)->toBeInstanceOf(DispatchSeed::class);
        $seeds[$seed->fqcn] = $seed->ambiguous;
    }

    return $seeds;
}

const SPEC_SITES = <<<'PHP'
namespace App\Http;

use App\Events\Fired;
use App\Events\Dispatchable;
use App\Jobs\Work;
use App\Mail\Receipt;
use App\Notifications\Ping;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class C
{
    public function go($user): void
    {
        event(new Fired);
        Dispatchable::dispatch();
        dispatch(new Work);
        Mail::to($user)->send(new Receipt);
        Notification::send($user, new Ping);
    }
}
PHP;

it('maps each spec to its convention directory', function () {
    expect((new EventClassSpec)->directory())->toBe(PrimitiveDirectory::EVENTS);
    expect((new JobClassSpec)->directory())->toBe(PrimitiveDirectory::JOBS);
    expect((new MailableClassSpec)->directory())->toBe(PrimitiveDirectory::MAIL);
    expect((new NotificationClassSpec)->directory())->toBe(PrimitiveDirectory::NOTIFICATIONS);
});

it('seeds events from direct forms and flags the Dispatchable form ambiguous', function () {
    expect(seedsOf(new EventClassSpec, SPEC_SITES))->toMatchArray([
        'App\\Events\\Fired' => false,
        'App\\Events\\Dispatchable' => true,
    ]);
});

it('seeds jobs from proven and Dispatchable forms', function () {
    expect(seedsOf(new JobClassSpec, SPEC_SITES))->toMatchArray([
        'App\\Jobs\\Work' => false,
        'App\\Events\\Dispatchable' => true,
    ]);
});

it('seeds mailables only from mail sends, never ambiguous', function () {
    expect(seedsOf(new MailableClassSpec, SPEC_SITES))->toBe(['App\\Mail\\Receipt' => false]);
});

it('seeds notifications only from notification sends, never ambiguous', function () {
    expect(seedsOf(new NotificationClassSpec, SPEC_SITES))->toBe(['App\\Notifications\\Ping' => false]);
});

it('admits ambiguous events only under the event directory', function () {
    $spec = new EventClassSpec;
    $location = new SourceLocation('app/X.php', 3);

    expect($spec->admitsAmbiguous($location, true))->toBeTrue();
    expect($spec->admitsAmbiguous($location, false))->toBeFalse();
});

it('admits ambiguous jobs under the job directory or when queued', function () {
    $spec = new JobClassSpec;
    $queue = new QueueConfigData(null, null, null, null, null, null);
    $queued = new JobLocation('app/J.php', 3, true, $queue);
    $plain = new JobLocation('app/J.php', 3, false, $queue);

    expect($spec->admitsAmbiguous($queued, false))->toBeTrue();
    expect($spec->admitsAmbiguous($plain, true))->toBeTrue();
    expect($spec->admitsAmbiguous($plain, false))->toBeFalse();
});

it('reads class records through the spec visitor', function () {
    $spec = new EventClassSpec;
    $record = new ClassRecord('App\\Events\\Fired', 7);

    expect($spec->fqcnOf($record))->toBe('App\\Events\\Fired');
    expect($spec->entryOf('App\\Events\\Fired', new SourceLocation('app/Events/Fired.php', 7)))
        ->toMatchObject(['id' => 'App\\Events\\Fired', 'fqcn' => 'App\\Events\\Fired', 'file' => 'app/Events/Fired.php', 'line' => 7]);
});
