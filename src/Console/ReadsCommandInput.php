<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Typed reads of artisan arguments and options, and the shared "read this
 * index file as a JSON object" step of the commands that take a path.
 * Used by {@see Command} subclasses.
 *
 * @internal
 */
trait ReadsCommandInput
{
    /** A string argument; '' when absent or not a string. */
    protected function stringArg(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    /** A string option; '' when absent or not a string. */
    protected function stringOpt(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /**
     * The string entries of a repeatable `--name=*` option, in order.
     *
     * @return list<string>
     */
    protected function stringListOpt(string $name): array
    {
        return array_values(Arr::where(
            (array) $this->option($name),
            static fn (mixed $value): bool => is_string($value),
        ));
    }

    /**
     * Read `$path` as a JSON object, reporting a failure through `error()`.
     *
     * @return array<string,mixed>|null null after an error line was printed
     */
    protected function decodeJsonObject(string $path): ?array
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
