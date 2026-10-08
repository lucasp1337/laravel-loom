<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Lucasp\Loom\Dto\SkippedFile;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\IndexPath;
use Lucasp\Loom\Support\OptionalPackage;
use Lucasp\Loom\Support\OptionalPackages;
use Lucasp\Loom\Support\ScanScope;

/** @internal */
class ScanCommand extends Command
{
    protected $signature = 'loom:scan
        {--output= : Write the index here instead of the configured index_path}
        {--path=* : Scan this directory (relative to the project root, repeatable) instead of scan.paths}';

    protected $description = 'Scan the application and write the index (default storage/loom/index.json)';

    public function handle(): int
    {
        $appRoot = $this->laravel->basePath();

        try {
            $scope = $this->resolveScope($appRoot);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($scope->directories($appRoot) === []) {
            $this->error('No scan directory exists for: '.implode(', ', $scope->paths()));

            return self::FAILURE;
        }

        $outputPath = $this->resolveOutputPath();

        $walker = new AstWalker;
        $builder = new IndexBuilder;
        DefaultScanners::registerOn($builder, $scope, $walker);

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

        try {
            $this->writeAtomically($outputPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Loom index written to {$outputPath}");
        $this->hintAtUi();

        $skipped = $walker->skippedFiles();
        $this->line($this->summary($payload, count($skipped)));

        if ($skipped !== [] && $this->output->isVerbose()) {
            $this->listSkipped($skipped, $appRoot);
        }

        return self::SUCCESS;
    }

    private function hintAtUi(): void
    {
        if (! (bool) config('loom.ui.enabled', true)
            || $this->laravel->make(OptionalPackages::class)->has(OptionalPackage::LIVEWIRE)) {
            return;
        }

        $this->line(OptionalPackage::LIVEWIRE->installHint());
    }

    private function resolveScope(string $appRoot): ScanScope
    {
        $config = config('loom.scan');
        $paths = array_values(array_filter((array) $this->option('path'), 'is_string'));

        return ScanScope::fromConfig(
            is_array($config) ? $config : [],
            $appRoot,
            $paths === [] ? null : $paths,
        );
    }

    private function resolveOutputPath(): string
    {
        $option = $this->option('output');
        if (! is_string($option) || $option === '') {
            return $this->laravel->make(IndexPath::class)->resolve();
        }

        $isAbsolute = str_starts_with($option, '/') || str_starts_with($option, '\\') || preg_match('#^[A-Za-z]:[\\/]#', $option) === 1;

        return $isAbsolute ? $option : $this->laravel->basePath($option);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summary(array $payload, int $skipped): string
    {
        $parts = [];
        foreach (Sections::cases() as $section) {
            $entries = $payload[$section->value] ?? [];
            $parts[] = str_replace('_', ' ', $section->value).': '.(is_array($entries) ? count($entries) : 0);
        }
        $parts[] = 'skipped files: '.$skipped.($skipped > 0 && ! $this->output->isVerbose() ? ' (-v lists them)' : '');

        return implode(', ', $parts);
    }

    /**
     * @param  list<SkippedFile>  $skipped
     */
    private function listSkipped(array $skipped, string $appRoot): void
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->line('Skipped files:');
        foreach ($skipped as $file) {
            $path = str_starts_with($file->file, $prefix) ? substr($file->file, strlen($prefix)) : $file->file;
            $location = str_replace(DIRECTORY_SEPARATOR, '/', $path).($file->line !== null ? ':'.$file->line : '');
            $this->line("  {$location}  {$file->message}");
        }
    }

    /** Readers never see a partial file: write a sibling temp file, then rename over the target. */
    private function writeAtomically(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create directory {$dir} for the Loom index");
        }

        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temp, $contents) === false || ! @rename($temp, $path)) {
            @unlink($temp);

            throw new \RuntimeException("Could not write Loom index to {$path}");
        }
    }

    private function detectLaravelVersion(): string
    {
        return class_exists(Application::class) ? Application::VERSION : 'unknown';
    }
}
