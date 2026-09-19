<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\IndexPath;

class ScanCommand extends Command
{
    protected $signature = 'loom:scan';

    protected $description = 'Scan the application and write the index (default storage/loom/index.json)';

    public function handle(): int
    {
        $appRoot = $this->laravel->basePath();
        $outputPath = $this->laravel->make(IndexPath::class)->resolve();

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

        $this->writeAtomically($outputPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Loom index written to {$outputPath}");

        return self::SUCCESS;
    }

    /** Readers never see a partial file: write a sibling temp file, then rename over the target. */
    private function writeAtomically(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        file_put_contents($temp, $contents);
        if (! rename($temp, $path)) {
            @unlink($temp);

            throw new \RuntimeException("Could not write Loom index to {$path}");
        }
    }

    private function detectLaravelVersion(): string
    {
        return class_exists(Application::class) ? Application::VERSION : 'unknown';
    }
}
