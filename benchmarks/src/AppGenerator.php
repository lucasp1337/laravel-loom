<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Filesystem\Filesystem;

/**
 * Materialises a synthetic Laravel-shaped app from a {@see BenchProfile} into a
 * directory. Generation is deterministic — same profile, same files, same
 * counts — so a benchmark run is reproducible and can be asserted against a
 * committed baseline. The files are wired (services dispatch generated jobs and
 * events, models register observers, routes target controllers) so every
 * scanner and the cross-linker have real work.
 */
final class AppGenerator
{
    /** Always-present files: Kernel, EventServiceProvider, bootstrap, web + api routes. */
    public const FIXED_FILES = 5;

    /** Scheduled-job entries are capped so `large`'s Kernel stays a sane size. */
    private const MAX_SCHEDULED_JOBS = 50;

    public function __construct(private readonly Filesystem $files = new Filesystem)
    {
    }

    public function generate(BenchProfile $profile, string $targetDir): void
    {
        $this->files->deleteDirectory($targetDir);

        $this->writeEvents($profile, $targetDir);
        $this->writeListeners($profile, $targetDir);
        $this->writeObservers($profile, $targetDir);
        $this->writeModels($profile, $targetDir);
        $this->writeJobs($profile, $targetDir);
        $this->writeMailables($profile, $targetDir);
        $this->writeNotifications($profile, $targetDir);
        $this->writeServices($profile, $targetDir);
        $this->writeControllers($profile, $targetDir);

        $this->writeProvider($targetDir);
        $this->writeKernel($profile, $targetDir);
        $this->writeBootstrap($targetDir);
        $this->writeRoutes($profile, $targetDir);
    }

    private function writeEvents(BenchProfile $profile, string $dir): void
    {
        for ($i = 0; $i < $profile->count('events'); $i++) {
            $this->put($dir, "app/Events/Event{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Events;

                class Event{$i}
                {
                    public function __construct(public int \$id = 0) {}
                }
                PHP);
        }
    }

    private function writeListeners(BenchProfile $profile, string $dir): void
    {
        $events = max(1, $profile->count('events'));
        for ($i = 0; $i < $profile->count('listeners'); $i++) {
            $e = $i % $events;
            $this->put($dir, "app/Listeners/Listener{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Listeners;

                use App\\Events\\Event{$e};

                class Listener{$i}
                {
                    public function handle(Event{$e} \$event): void {}
                }
                PHP);
        }
    }

    private function writeObservers(BenchProfile $profile, string $dir): void
    {
        $models = max(1, $profile->count('models'));
        for ($i = 0; $i < $profile->count('observers'); $i++) {
            $m = $i % $models;
            $this->put($dir, "app/Observers/Observer{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Observers;

                use App\\Models\\Model{$m};

                class Observer{$i}
                {
                    public function created(Model{$m} \$model): void {}

                    public function updated(Model{$m} \$model): void {}

                    public function deleted(Model{$m} \$model): void {}
                }
                PHP);
        }
    }

    private function writeModels(BenchProfile $profile, string $dir): void
    {
        $observers = max(1, $profile->count('observers'));
        for ($i = 0; $i < $profile->count('models'); $i++) {
            $o = $i % $observers;
            $this->put($dir, "app/Models/Model{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Models;

                use App\\Observers\\Observer{$o};
                use Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy;

                #[ObservedBy(Observer{$o}::class)]
                class Model{$i}
                {
                }
                PHP);
        }
    }

    private function writeJobs(BenchProfile $profile, string $dir): void
    {
        for ($i = 0; $i < $profile->count('jobs'); $i++) {
            $this->put($dir, "app/Jobs/Job{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Jobs;

                use Illuminate\\Contracts\\Queue\\ShouldQueue;

                class Job{$i} implements ShouldQueue
                {
                    public string \$queue = 'default';

                    public function handle(): void {}
                }
                PHP);
        }
    }

    private function writeMailables(BenchProfile $profile, string $dir): void
    {
        for ($i = 0; $i < $profile->count('mailables'); $i++) {
            $this->put($dir, "app/Mail/Mailable{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Mail;

                use Illuminate\\Mail\\Mailable;

                class Mailable{$i} extends Mailable
                {
                }
                PHP);
        }
    }

    private function writeNotifications(BenchProfile $profile, string $dir): void
    {
        for ($i = 0; $i < $profile->count('notifications'); $i++) {
            $this->put($dir, "app/Notifications/Notification{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Notifications;

                use Illuminate\\Notifications\\Notification;

                class Notification{$i} extends Notification
                {
                    public function via(object \$notifiable): array
                    {
                        return ['mail'];
                    }
                }
                PHP);
        }
    }

    private function writeServices(BenchProfile $profile, string $dir): void
    {
        $events = max(1, $profile->count('events'));
        $jobs = max(1, $profile->count('jobs'));
        for ($i = 0; $i < $profile->count('services'); $i++) {
            $e = $i % $events;
            $j = $i % $jobs;
            $this->put($dir, "app/Services/Service{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Services;

                use App\\Events\\Event{$e};
                use App\\Jobs\\Job{$j};

                class Service{$i}
                {
                    public function handle(): void
                    {
                        event(new Event{$e}());
                        Job{$j}::dispatch();
                    }
                }
                PHP);
        }
    }

    private function writeControllers(BenchProfile $profile, string $dir): void
    {
        for ($i = 0; $i < $profile->count('controllers'); $i++) {
            $this->put($dir, "app/Http/Controllers/Controller{$i}.php", <<<PHP
                <?php

                declare(strict_types=1);

                namespace App\\Http\\Controllers;

                class Controller{$i}
                {
                    public function index() {}

                    public function store() {}

                    public function show() {}
                }
                PHP);
        }
    }

    private function writeProvider(string $dir): void
    {
        $this->put($dir, 'app/Providers/EventServiceProvider.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Providers;

            use App\Events\Event0;
            use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

            class EventServiceProvider extends ServiceProvider
            {
                protected $listen = [
                    Event0::class => [
                        fn ($e) => null,
                        function ($e) { return null; },
                    ],
                ];
            }
            PHP);
    }

    private function writeKernel(BenchProfile $profile, string $dir): void
    {
        $body = "        \$schedule->command('inspire')->daily();\n";
        $body .= "        \$schedule->command('queue:prune-batches')->weekly();\n";
        $body .= "        \$schedule->command('telescope:prune')->hourly();\n";

        $jobs = min($profile->count('jobs'), self::MAX_SCHEDULED_JOBS);
        for ($i = 0; $i < $jobs; $i++) {
            $body .= "        \$schedule->job(\\App\\Jobs\\Job{$i}::class)->everyFiveMinutes();\n";
        }

        $this->put($dir, 'app/Console/Kernel.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Console;

            use Illuminate\\Console\\Scheduling\\Schedule;

            class Kernel
            {
                protected function schedule(Schedule \$schedule): void
                {
            {$body}    }
            }
            PHP);
    }

    private function writeBootstrap(string $dir): void
    {
        $this->put($dir, 'bootstrap/app.php', <<<'PHP'
            <?php

            use Illuminate\Foundation\Application;

            return Application::configure(basePath: dirname(__DIR__))->create();
            PHP);
    }

    private function writeRoutes(BenchProfile $profile, string $dir): void
    {
        $web = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n";
        $api = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n";

        for ($i = 0; $i < $profile->count('controllers'); $i++) {
            $block = "use App\\Http\\Controllers\\Controller{$i};\n"
                ."Route::get('/r{$i}', [Controller{$i}::class, 'index'])->name('r{$i}.index');\n"
                ."Route::post('/r{$i}', [Controller{$i}::class, 'store']);\n"
                ."Route::get('/r{$i}/{id}', [Controller{$i}::class, 'show']);\n\n";

            if ($i % 2 === 0) {
                $web .= $block;
            } else {
                $api .= $block;
            }
        }

        $this->put($dir, 'routes/web.php', $web);
        $this->put($dir, 'routes/api.php', $api);
    }

    private function put(string $dir, string $relativePath, string $contents): void
    {
        $path = $dir.'/'.$relativePath;
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, rtrim($contents)."\n");
    }
}
