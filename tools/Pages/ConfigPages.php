<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;

/**
 * The config key tables, read from config/loom.php: key, environment
 * variable, default and the comment written above the key.
 */
final class ConfigPages implements RegionSource
{
    private const SCAN_PAGE = 'docs/reference/scan-config.md';

    private const UI_PAGE = 'docs/reference/ui-config.md';

    public function __construct(private readonly string $root) {}

    public function regions(): array
    {
        $entries = $this->entries();

        return [
            'config:scan' => ['page' => self::SCAN_PAGE, 'body' => $this->table($entries, ['scan.'])],
            'config:index' => ['page' => self::UI_PAGE, 'body' => $this->table($entries, ['index_path'])],
            'config:ui' => ['page' => self::UI_PAGE, 'body' => $this->table($entries, ['ui.'])],
            'config:mcp' => ['page' => self::UI_PAGE, 'body' => $this->table($entries, ['mcp.'])],
        ];
    }

    /**
     * @param  list<array{key: string, env: string|null, default: string, comment: string}>  $entries
     * @param  list<string>  $prefixes  dotted keys to include; a trailing dot takes a whole group
     */
    private function table(array $entries, array $prefixes): string
    {
        $see = (require $this->root.'/tools/reference/config.php')['see'];
        $chosen = array_values(array_filter($entries, static function (array $entry) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if ($prefix === $entry['key'] || (str_ends_with($prefix, '.') && str_starts_with($entry['key'], $prefix))) {
                    return true;
                }
            }

            return false;
        }));
        $hasEnv = Arr::first($chosen, static fn (array $e): bool => $e['env'] !== null) !== null;

        $rows = [];
        foreach ($chosen as $entry) {
            $row = ['`'.$entry['key'].'`'];
            if ($hasEnv) {
                $row[] = $entry['env'] === null ? 'none' : "`{$entry['env']}`";
            }
            $row[] = '`'.$entry['default'].'`';
            $row[] = Markdown::cell(trim($entry['comment'].' '.($see[$entry['key']] ?? '')));
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
        $path = $this->root.'/config/loom.php';
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
