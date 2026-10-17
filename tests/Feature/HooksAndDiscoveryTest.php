<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\ObserverScanner;

/** Builds a throwaway app from a path => source map and returns its root. */
function hooksApp(array $files): string
{
    $root = sys_get_temp_dir().'/loom-hooks-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $source) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", $source);
    }

    return $root;
}

function hooksIndex(string $root): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new ObserverScanner);
    $builder->register(new DispatchScanner);

    return $builder->build($root, '12.x')->toArray();
}

const HOOK_LISTENER = <<<'PHP'
<?php
namespace App\Listeners;
use App\Events\OrderShipped;
class ShipListener { public function handle(OrderShipped $e): void {} }
PHP;

const HOOK_EVENT = <<<'PHP'
<?php
namespace App\Events;
class OrderShipped {}
PHP;

it('emits closure model hooks as closure listeners and model events with their dispatches', function () {
    $root = hooksApp([
        'app/Events/OrderDeleted.php' => "<?php\nnamespace App\\Events;\nclass OrderDeleted { public static function dispatch(...\$a) {} }\n",
        'app/Models/Order.php' => <<<'PHP'
        <?php
        namespace App\Models;
        use App\Events\OrderDeleted;
        class Order
        {
            protected static function booted(): void
            {
                static::deleted(fn (Order $order) => OrderDeleted::dispatch($order));
            }
        }
        PHP,
    ]);

    $index = hooksIndex($root);

    $closure = collect($index['closure_listeners'])->firstWhere('registration', 'model_hook');
    expect($closure['event'])->toBe('eloquent.deleted: App\Models\Order');
    expect(collect($closure['dispatches'])->pluck('target')->all())->toContain('App\Events\OrderDeleted');

    $event = collect($index['model_events'])->firstWhere('id', 'eloquent.deleted: App\Models\Order');
    expect($event['handled_by'][0])->toMatchArray(['handler' => 'App\Models\Order', 'method' => '{closure}', 'file' => 'app/Models/Order.php']);
});

it('expands a trait hook to every class using the trait and reports an unused trait quietly', function () {
    $root = hooksApp([
        'app/Concerns/HasUuid.php' => "<?php\nnamespace App\\Concerns;\ntrait HasUuid { public static function bootHasUuid(): void { static::creating(fn (\$m) => 1); } }\n",
        'app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order { use \\App\\Concerns\\HasUuid; }\n",
        'app/Models/Invoice.php' => "<?php\nnamespace App\\Models;\nclass Invoice { use \\App\\Concerns\\HasUuid; }\n",
    ]);

    $ids = collect(hooksIndex($root)['model_events'])->pluck('id')->all();

    expect($ids)->toBe(['eloquent.creating: App\Models\Invoice', 'eloquent.creating: App\Models\Order']);
});

it('reports an unreadable hook callback', function () {
    $root = hooksApp([
        'app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order { protected static function booted(): void { static::created(\$this->cb); } }\n",
    ]);

    $unresolved = hooksIndex($root)['unresolved_dispatches'];

    expect($unresolved)->toHaveCount(1);
    expect($unresolved[0])->toMatchArray(['reason' => 'unreadable_model_hook', 'file' => 'app/Models/Order.php']);
});

it('keeps a listener typed to a parent event off the child event', function () {
    $root = hooksApp([
        'bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->create();\n",
        'app/Events/BaseEvent.php' => "<?php\nnamespace App\\Events;\nabstract class BaseEvent {}\n",
        'app/Events/ChildEvent.php' => "<?php\nnamespace App\\Events;\nclass ChildEvent extends BaseEvent {}\n",
        'app/Listeners/OnBase.php' => "<?php\nnamespace App\\Listeners;\nuse App\\Events\\BaseEvent;\nclass OnBase { public function handle(BaseEvent \$e): void {} }\n",
    ]);

    $events = collect(hooksIndex($root)['events']);

    expect($events->firstWhere('fqcn', 'App\Events\ChildEvent')['handled_by'])->toBe([]);
});

dataset('discovery', [
    'configure default' => [['bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents()->create();\n"], 'auto_discovered'],
    'withEvents false' => [['bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents(false)->create();\n"], null],
    'withEvents discover false' => [['bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents(discover: false)->create();\n"], null],
    'withEvents other directory' => [['bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents([__DIR__.'/../app/Handlers'])->create();\n"], null],
    'withEvents dynamic' => [['bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents(\$flag)->create();\n"], 'discovery_unverified'],
    'no bootstrap' => [[], 'discovery_unverified'],
    'legacy no override' => [['bootstrap/app.php' => "<?php\n\$app = new \\Illuminate\\Foundation\\Application(__DIR__);\nreturn \$app;\n"], null],
    'legacy override true' => [[
        'bootstrap/app.php' => "<?php\n\$app = new \\Illuminate\\Foundation\\Application(__DIR__);\nreturn \$app;\n",
        'app/Providers/EventServiceProvider.php' => "<?php\nnamespace App\\Providers;\nclass EventServiceProvider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { public function shouldDiscoverEvents() { return true; } }\n",
    ], 'auto_discovered'],
    'disable call' => [[
        'bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->create();\n",
        'app/Providers/AppServiceProvider.php' => "<?php\nnamespace App\\Providers;\nuse Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider;\nclass AppServiceProvider { public function register(): void { EventServiceProvider::disableEventDiscovery(); } }\n",
    ], null],
]);

it('claims auto_discovered only when discovery is verified on', function (array $files, ?string $expected) {
    $root = hooksApp([
        'app/Events/OrderShipped.php' => HOOK_EVENT,
        'app/Listeners/ShipListener.php' => HOOK_LISTENER,
        ...$files,
    ]);

    $listener = collect(hooksIndex($root)['listeners'])->firstWhere('fqcn', 'App\Listeners\ShipListener');

    expect($listener['registration'] ?? null)->toBe($expected);
})->with('discovery');

it('discovers from configured directories', function () {
    $root = hooksApp([
        'bootstrap/app.php' => "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__)->withEvents([__DIR__.'/../app/Handlers'])->create();\n",
        'app/Events/OrderShipped.php' => HOOK_EVENT,
        'app/Handlers/ShipHandler.php' => str_replace(['Listeners', 'ShipListener'], ['Handlers', 'ShipHandler'], HOOK_LISTENER),
        'app/Listeners/ShipListener.php' => HOOK_LISTENER,
    ]);

    $listeners = collect(hooksIndex($root)['listeners'])->pluck('registration', 'fqcn')->all();

    expect($listeners)->toBe(['App\Handlers\ShipHandler' => 'auto_discovered']);
});
