<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Lucasp\Loom\Diff\Format\FormatterFactory;
use Lucasp\Loom\Diff\Format\UnknownDiffFormatException;
use Lucasp\Loom\Diff\IndexDiffer;

class DiffCommand extends Command
{
    protected $signature = 'loom:diff {old : Path to the old index.json} {new : Path to the new index.json} {--format=text : Output format (text|json|markdown)}';

    protected $description = 'Semantically diff two Loom index.json files';

    /**
     * Exit codes are non-standard here and intentionally NOT the Command
     * SUCCESS/FAILURE constants: 0 = no changes, 1 = changes exist (still a
     * successful run, mirroring `git diff --exit-code`), 2 = a real error
     * (bad path, invalid JSON, unknown format).
     */
    public function handle(IndexDiffer $differ, FormatterFactory $formatters): int
    {
        $old = $this->decode($this->stringArg('old'));
        $new = $this->decode($this->stringArg('new'));
        if ($old === null || $new === null) {
            return 2;
        }

        try {
            $formatter = $formatters->make($this->stringOpt('format'));
        } catch (UnknownDiffFormatException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $result = $differ->diff($old, $new);
        $this->line($formatter->format($result));

        return $result->hasChanges() ? 1 : 0;
    }

    private function stringArg(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOpt(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Not a file: {$path}");

            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read {$path}.");

            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $this->error("{$path} is not a valid JSON object.");

            return null;
        }

        /** @var array<string,mixed> $data */
        return $data;
    }
}
