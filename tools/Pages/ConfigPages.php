<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;
use Lucasp\Loom\Tools\Reference\ConfigGroup;
use Lucasp\Loom\Tools\Reference\ConfigSeeAlso;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\SourceContext;

/**
 * The config key tables, read from config/loom.php: key, environment
 * variable, default and the comment written above the key.
 */
final class ConfigPages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        $entries = $this->entries();

        return array_map(
            fn (ConfigGroup $group): GeneratedRegion => new GeneratedRegion($group->region(), $this->table($entries, $group)),
            ConfigGroup::cases(),
        );
    }

    /**
     * @param  list<array{key: string, env: string|null, default: string, comment: string}>  $entries
     */
    private function table(array $entries, ConfigGroup $group): string
    {
        $chosen = array_values(array_filter($entries, static fn (array $entry): bool => $group->includes($entry['key'])));
        $hasEnv = Arr::first($chosen, static fn (array $e): bool => $e['env'] !== null) !== null;

        $rows = [];
        foreach ($chosen as $entry) {
            $row = ['`'.$entry['key'].'`'];
            if ($hasEnv) {
                $row[] = $entry['env'] === null ? 'none' : "`{$entry['env']}`";
            }
            $row[] = '`'.$entry['default'].'`';
            $row[] = Markdown::cell(trim($entry['comment'].' '.ConfigSeeAlso::for($entry['key'])));
            $rows[] = $row;
        }

        $header = $hasEnv ? ['Key', 'Env variable', 'Default', 'Meaning'] : ['Key', 'Default', 'Meaning'];

        return Markdown::table($header, $rows);
    }

    /**
     * @return list<array{key: string, env: string|null, default: string, comment: string}>
     */
    private function entries(): array
    {
        $path = $this->context->root.'/config/loom.php';
        $config = require $path;
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $entries = [];
        $group = null;
        $comment = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if (str_starts_with($trim, '//')) {
                $comment[] = trim(substr($trim, 2));

                continue;
            }
            if (preg_match("/^'([a-z0-9_]+)' => \[\s*$/", $trim, $m) === 1 && str_starts_with($line, '    \'')) {
                $group = $m[1];
                $comment = [];

                continue;
            }
            if (preg_match("/^'([a-z0-9_]+)' =>/", $trim, $m) === 1) {
                $top = ! str_starts_with($line, '        ');
                $key = $top ? $m[1] : "{$group}.{$m[1]}";
                $value = $top ? $config[$m[1]] : $config[$group][$m[1]];
                $entries[] = [
                    'key' => $key,
                    'env' => preg_match("/env\('([A-Z_]+)'/", $trim, $e) === 1 ? $e[1] : null,
                    'default' => $this->exportDefault($value, true),
                    'comment' => implode(' ', $comment),
                ];
                $comment = [];

                continue;
            }
            if ($trim === '') {
                $comment = [];
            }
        }

        return $entries;
    }

    private function exportDefault(mixed $value, bool $top = false): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => '['.implode(', ', Arr::map($value, fn (mixed $v): string => $this->exportDefault($v))).']',
            is_int($value) => (string) $value,
            $top => (string) $value,
            default => "'".(string) $value."'",
        };
    }
}
