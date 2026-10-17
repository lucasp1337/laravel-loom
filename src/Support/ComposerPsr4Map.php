<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;

/**
 * PSR-4 prefix → directory map read from the scanned app's composer.json.
 * Mirrors Composer's lookup: longest matching prefix first, then the empty
 * prefix as a fallback, each prefix trying its directories in order.
 *
 * @internal
 */
final class ComposerPsr4Map
{
    private const DEFAULT_PREFIX = 'App\\';

    private const DEFAULT_DIRECTORY = 'app';

    /** @var array<string, list<string>> prefix (with trailing backslash, or '') => directories relative to the app root */
    private array $map;

    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(array $map)
    {
        $map = collect($map)->sortKeysUsing(fn (string $a, string $b): int => strlen($b) <=> strlen($a))->all();
        $this->map = $map;
    }

    /** Laravel's skeleton mapping, used when composer.json is missing or declares no PSR-4 autoload. */
    public static function laravelDefault(): self
    {
        return new self([self::DEFAULT_PREFIX => [self::DEFAULT_DIRECTORY]]);
    }

    /**
     * The root composer.json plus the files it pulls in through
     * `extra.merge-plugin.include` and the configured `$extraFiles` (globs
     * relative to the app root). Only `autoload.psr-4`; `autoload-dev` (tests,
     * factories) is not application code. Directories are relative to the app
     * root, whichever file declared them.
     *
     * @param  list<string>  $extraFiles
     * @param  bool  $includeRoot  false reads only the included files
     */
    public static function fromAppRoot(string $appRoot, array $extraFiles = [], bool $includeRoot = true): self
    {
        $root = $includeRoot ? self::decode(AppPath::join($appRoot, 'composer.json')) : [];
        $files = array_values(collect([...self::mergePluginIncludes($root), ...$extraFiles])->unique(strict: true)->all());

        $map = [];
        self::collect($map, $root['autoload']['psr-4'] ?? null, '');

        foreach (self::composerFiles($appRoot, $files) as $file) {
            $relativeDir = Str::chopEnd(Str::chopEnd((string) AppPath::relative($appRoot, $file), 'composer.json'), '/');
            self::collect($map, self::decode($file)['autoload']['psr-4'] ?? null, $relativeDir);
        }

        return $map === [] && $includeRoot ? self::laravelDefault() : new self($map);
    }

    /**
     * Existing composer.json files (absolute, sorted) matching globs relative
     * to the app root.
     *
     * @param  list<string>  $globs
     * @return list<string>
     */
    public static function composerFiles(string $appRoot, array $globs): array
    {
        $found = [];
        $root = preg_replace('/[*?\[\]]/', '[$0]', AppPath::root($appRoot)) ?? AppPath::root($appRoot);

        foreach ($globs as $glob) {
            foreach (glob($root.'/'.Str::trim(Str::replace('\\', '/', $glob), '/')) ?: [] as $match) {
                if (is_file($match) && Str::endsWith($match, 'composer.json')) {
                    $found[Str::replace('/', DIRECTORY_SEPARATOR, $match)] = true;
                }
            }
        }

        return array_values(collect(array_keys($found))->sort()->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $file): array
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private static function mergePluginIncludes(array $root): array
    {
        $include = $root['extra']['merge-plugin']['include'] ?? [];

        return array_values(collect((array) $include)->filter(fn (mixed $v): bool => is_string($v))->all());
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private static function collect(array &$map, mixed $psr4, string $baseDir): void
    {
        if (! is_array($psr4)) {
            return;
        }

        foreach ($psr4 as $prefix => $dirs) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && ! Str::endsWith($prefix, '\\')) {
                continue;
            }

            foreach ((array) $dirs as $dir) {
                if (! is_string($dir)) {
                    continue;
                }
                $dir = Str::trim(Str::replace('\\', '/', $dir), '/');
                $map[$prefix][] = Str::trim($baseDir.'/'.$dir, '/');
            }
        }
    }

    /**
     * Absolute path of the file that would hold $fqcn, or null when none exists.
     */
    public function locate(string $appRoot, string $fqcn): ?string
    {
        $fqcn = Fqcn::normalize($fqcn);
        if ($fqcn === '') {
            return null;
        }

        $root = AppPath::root($appRoot);

        foreach ($this->map as $prefix => $dirs) {
            if ($prefix !== '' && ! Str::startsWith($fqcn, $prefix)) {
                continue;
            }

            $tail = Str::replace('\\', '/', Str::chopStart($fqcn, $prefix)).'.php';
            foreach ($dirs as $dir) {
                $candidate = $root.'/'.($dir === '' ? '' : $dir.'/').$tail;
                if (is_file($candidate)) {
                    return Str::replace('/', DIRECTORY_SEPARATOR, $candidate);
                }
            }
        }

        return null;
    }

    /**
     * Distinct directories (relative to the app root, forward slashes).
     *
     * @return list<string>
     */
    public function directories(): array
    {
        $dirs = [];
        foreach ($this->map as $list) {
            foreach ($list as $dir) {
                if ($dir !== '') {
                    $dirs[$dir] = true;
                }
            }
        }

        return array_keys($dirs);
    }
}
