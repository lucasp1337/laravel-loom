<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\CheckRunner;
use Lucasp\Loom\Check\Format\CheckFormatterFactory;
use Lucasp\Loom\Check\Format\UnknownCheckFormatException;
use Lucasp\Loom\Check\RuleKey;

class CheckCommand extends Command
{
    protected $signature = 'loom:check
        {index? : Path to index.json (default: storage/loom/index.json)}
        {--baseline= : Path to a baseline index.json for growth checks}
        {--strict : Treat any unresolved dispatch as a failure}
        {--skip=* : Rule keys to skip}
        {--format=text : Output format (text|json|markdown)}';

    protected $description = 'Run policy checks against a Loom index (CI gate)';

    /**
     * Exit codes mirror DiffCommand's convention: 0 = all checks passed,
     * 1 = violations found (a successful run that failed policy), 2 = an
     * invocation error (bad path, invalid JSON, unknown format or rule key).
     */
    public function handle(CheckRunner $runner, CheckFormatterFactory $formatters): int
    {
        $indexPath = $this->stringArg('index');
        if ($indexPath === '') {
            $indexPath = $this->laravel->storagePath('loom/index.json');
        }

        $index = $this->decode($indexPath);
        if ($index === null) {
            return 2;
        }

        $baseline = null;
        $baselinePath = $this->stringOpt('baseline');
        if ($baselinePath !== '') {
            $baseline = $this->decode($baselinePath);
            if ($baseline === null) {
                return 2;
            }
        }

        $skip = $this->resolveSkip();
        if ($skip === null) {
            return 2;
        }

        try {
            $formatter = $formatters->make($this->stringOpt('format'));
        } catch (UnknownCheckFormatException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $context = new CheckContext($index, $baseline, (bool) $this->option('strict'));
        $result = $runner->run($context, $skip);
        $this->line($formatter->format($result));

        return $result->passed() ? 0 : 1;
    }

    /**
     * @return list<string>|null null signals an invalid rule key
     */
    private function resolveSkip(): ?array
    {
        $raw = $this->option('skip');
        if (! is_array($raw)) {
            return [];
        }

        $skip = [];
        foreach ($raw as $value) {
            $value = is_string($value) ? $value : '';
            if (RuleKey::tryFrom($value) === null) {
                $valid = implode(', ', array_map(static fn (RuleKey $key): string => $key->value, RuleKey::cases()));
                $this->error("Unknown rule key '{$value}'. Valid keys: {$valid}.");

                return null;
            }
            $skip[] = $value;
        }

        return $skip;
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
