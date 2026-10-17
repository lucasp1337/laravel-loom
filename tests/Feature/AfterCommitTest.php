<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;

/**
 * Scans a throwaway app of queued classes and returns `queue_config.after_commit`
 * by FQCN across jobs, mailables and notifications.
 *
 * @param  array<string, string>  $files  path under the app root => PHP source
 * @return array<string, array<string, mixed>>
 */
function queueConfigsOf(array $files): array
{
    $root = sys_get_temp_dir().'/loom-after-commit-'.bin2hex(random_bytes(6));
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

    return collect([...$payload['jobs'], ...$payload['mailables'], ...$payload['notifications']])
        ->mapWithKeys(fn (array $entry): array => [$entry['fqcn'] => $entry['queue_config']])
        ->all();
}

function queuedClass(string $dir, string $name, string $implements, string $parent, string $body = ''): array
{
    $ns = 'App\\'.$dir;
    $uses = "use Illuminate\\Contracts\\Queue\\ShouldQueue;\nuse Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit;\n";

    return ["app/{$dir}/{$name}.php" => "<?php\n\nnamespace {$ns};\n\n{$uses}\nclass {$name} extends {$parent} implements {$implements}\n{\n{$body}\n}\n"];
}

it('reads after_commit for jobs: property, constructor call, interface and opt-out', function () {
    $configs = queueConfigsOf(
        queuedClass('Jobs', 'Plain', 'ShouldQueue', '\\App\\Base')
        + queuedClass('Jobs', 'ByProperty', 'ShouldQueue', '\\App\\Base', 'public $afterCommit = true;')
        + queuedClass('Jobs', 'ByFalse', 'ShouldQueue', '\\App\\Base', 'public $afterCommit = false;')
        + queuedClass('Jobs', 'ByCall', 'ShouldQueue', '\\App\\Base', 'public function __construct() { $this->afterCommit(); }')
        + queuedClass('Jobs', 'ByBefore', 'ShouldQueue', '\\App\\Base', 'public function __construct() { $this->beforeCommit(); }')
        + queuedClass('Jobs', 'ByInterface', 'ShouldQueueAfterCommit', '\\App\\Base')
        + queuedClass('Jobs', 'InterfaceOptOut', 'ShouldQueueAfterCommit', '\\App\\Base', 'public $afterCommit = false;')
        + queuedClass('Jobs', 'InterfaceBeforeCommit', 'ShouldQueueAfterCommit', '\\App\\Base', 'public function __construct() { $this->beforeCommit(); }'),
    );

    expect($configs['App\\Jobs\\Plain']['after_commit'])->toBeNull()
        ->and($configs['App\\Jobs\\ByProperty']['after_commit'])->toBeTrue()
        ->and($configs['App\\Jobs\\ByFalse']['after_commit'])->toBeFalse()
        ->and($configs['App\\Jobs\\ByCall']['after_commit'])->toBeTrue()
        ->and($configs['App\\Jobs\\ByBefore']['after_commit'])->toBeFalse()
        ->and($configs['App\\Jobs\\ByInterface']['after_commit'])->toBeTrue()
        // Queue::shouldDispatchAfterCommit honours `$afterCommit === false` on a ShouldQueueAfterCommit job
        ->and($configs['App\\Jobs\\InterfaceOptOut']['after_commit'])->toBeFalse()
        ->and($configs['App\\Jobs\\InterfaceBeforeCommit']['after_commit'])->toBeFalse();
});

it('reads after_commit through a parent class and a trait', function () {
    $files = [
        'app/Jobs/Base.php' => "<?php\n\nnamespace App\\Jobs;\n\nabstract class Base { public \$afterCommit = true; }\n",
        'app/Jobs/Concern.php' => "<?php\n\nnamespace App\\Jobs;\n\ntrait Concern { public \$afterCommit = true; }\n",
    ]
        + queuedClass('Jobs', 'Child', 'ShouldQueue', 'Base')
        + queuedClass('Jobs', 'Overrides', 'ShouldQueue', 'Base', 'public $afterCommit = false;')
        + queuedClass('Jobs', 'UsesConcern', 'ShouldQueue', '\\App\\Other', 'use Concern;');

    $configs = queueConfigsOf($files + ['app/Other.php' => "<?php\n\nnamespace App;\n\nclass Other {}\n"]);

    expect($configs['App\\Jobs\\Child']['after_commit'])->toBeTrue()
        ->and($configs['App\\Jobs\\Overrides']['after_commit'])->toBeFalse()
        ->and($configs['App\\Jobs\\UsesConcern']['after_commit'])->toBeTrue();
});

it('takes ShouldQueueAfterCommit as true on mailables and notifications even with a false property', function () {
    $configs = queueConfigsOf(
        queuedClass('Mail', 'PlainMail', 'ShouldQueue', '\\Illuminate\\Mail\\Mailable')
        + queuedClass('Mail', 'PropertyMail', 'ShouldQueue', '\\Illuminate\\Mail\\Mailable', 'public $afterCommit = true;')
        + queuedClass('Mail', 'InterfaceMail', 'ShouldQueueAfterCommit', '\\Illuminate\\Mail\\Mailable')
        + queuedClass('Mail', 'InterfaceFalseMail', 'ShouldQueueAfterCommit', '\\Illuminate\\Mail\\Mailable', 'public $afterCommit = false;')
        + queuedClass('Notifications', 'PlainNote', 'ShouldQueue', '\\Illuminate\\Notifications\\Notification')
        + queuedClass('Notifications', 'InterfaceNote', 'ShouldQueueAfterCommit', '\\Illuminate\\Notifications\\Notification')
        + queuedClass('Notifications', 'InterfaceFalseNote', 'ShouldQueueAfterCommit', '\\Illuminate\\Notifications\\Notification', 'public $afterCommit = false;'),
    );

    expect($configs['App\\Mail\\PlainMail']['after_commit'])->toBeNull()
        ->and($configs['App\\Mail\\PropertyMail']['after_commit'])->toBeTrue()
        ->and($configs['App\\Mail\\InterfaceMail']['after_commit'])->toBeTrue()
        // SendQueuedMailable / SendQueuedNotifications set afterCommit = true for the interface without reading the property
        ->and($configs['App\\Mail\\InterfaceFalseMail']['after_commit'])->toBeTrue()
        ->and($configs['App\\Notifications\\PlainNote']['after_commit'])->toBeNull()
        ->and($configs['App\\Notifications\\InterfaceNote']['after_commit'])->toBeTrue()
        ->and($configs['App\\Notifications\\InterfaceFalseNote']['after_commit'])->toBeTrue();
});
