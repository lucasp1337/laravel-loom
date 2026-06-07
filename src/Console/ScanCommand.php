<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

class ScanCommand extends Command
{
    protected $signature = 'loom:scan';

    protected $description = 'Scan the application and write storage/loom/index.json';

    public function handle(): int
    {
        $appRoot = $this->laravel->basePath();
        $outputPath = $this->laravel->storagePath('loom/index.json');

        $builder = new IndexBuilder;
        DefaultScanners::registerOn($builder);

        $index = $builder->build($appRoot, $this->detectLaravelVersion());
        $payload = $index->toArray();

        $errors = $builder->validate($payload);
        if ($errors !== []) {
            $this->error('Loom index failed schema validation:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        file_put_contents($outputPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Loom index written to {$outputPath}");

        return self::SUCCESS;
    }

    private function detectLaravelVersion(): string
    {
        return class_exists(Application::class) ? Application::VERSION : 'unknown';
    }
}
