<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Console;

use Illuminate\Console\Command;

class ShowCommand extends Command
{
    protected $signature = 'atlas:show {filter? : Optional FQCN substring filter}';

    protected $description = 'Print the Atlas index, optionally filtered by FQCN substring';

    public function handle(): int
    {
        $path = $this->laravel->storagePath('atlas/index.json');
        if (! is_file($path)) {
            $this->error("Atlas index not found at {$path}. Run `php artisan atlas:scan` first.");

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read {$path}.");

            return self::FAILURE;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $this->error("Atlas index at {$path} is not valid JSON.");

            return self::FAILURE;
        }

        $filter = $this->argument('filter');
        if (is_string($filter) && $filter !== '') {
            $data = $this->filter($data, $filter);
        }

        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filter(array $data, string $needle): array
    {
        foreach (['events', 'listeners', 'observers', 'model_events'] as $section) {
            if (! isset($data[$section]) || ! is_array($data[$section])) {
                continue;
            }
            $data[$section] = array_values(array_filter(
                $data[$section],
                fn ($entry) => is_array($entry) && str_contains(json_encode($entry) ?: '', $needle),
            ));
        }

        return $data;
    }
}
