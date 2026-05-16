<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Lucasp\Atlas\Index\IndexBuilder;
use Lucasp\Atlas\Scanners\EventScanner;
use Lucasp\Atlas\Scanners\ListenerScanner;
use Lucasp\Atlas\Scanners\ObserverScanner;

class ScanCommand extends Command
{
    protected $signature = 'atlas:scan';

    protected $description = 'Scan the application and write storage/atlas/index.json';

    public function handle(): int
    {
        $appRoot = $this->laravel->basePath();
        $outputPath = $this->laravel->storagePath('atlas/index.json');

        $builder = new IndexBuilder;
        $builder->register(new EventScanner);
        $builder->register(new ListenerScanner);
        $builder->register(new ObserverScanner);

        $index = $builder->build($appRoot, $this->detectLaravelVersion());
        $payload = $index->toArray();

        $errors = $builder->validate($payload);
        if ($errors !== []) {
            $this->error('Atlas index failed schema validation:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        file_put_contents($outputPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Atlas index written to {$outputPath}");

        return self::SUCCESS;
    }

    private function detectLaravelVersion(): string
    {
        return class_exists(Application::class) ? Application::VERSION : 'unknown';
    }
}
