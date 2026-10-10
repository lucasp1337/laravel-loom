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
        uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->map = $map;
    }

    /** Laravel's skeleton mapping, used when composer.json is missing or declares no PSR-4 autoload. */
    public static function laravelDefault(): self
    {
        return new self([self::DEFAULT_PREFIX => [self::DEFAULT_DIRECTORY]]);
    }

    /** Only `autoload.psr-4`; `autoload-dev` (tests, factories) is not application code. */
    public static function fromAppRoot(string $appRoot): self
    {
        $file = Str::rtrim($appRoot, '/\\').DIRECTORY_SEPARATOR.'composer.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);

        $psr4 = is_array($decoded) && is_array($decoded['autoload'] ?? null)
            ? ($decoded['autoload']['psr-4'] ?? null)
            : null;

        if (! is_array($psr4)) {
            return self::laravelDefault();
        }

        $map = [];
        foreach ($psr4 as $prefix => $dirs) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && ! Str::endsWith($prefix, '\\')) {
                continue;
            }

            foreach ((array) $dirs as $dir) {
                if (! is_string($dir)) {
                    continue;
                }
                $map[$prefix][] = Str::trim(Str::replace('\\', '/', $dir), '/');
            }
        }

        return $map === [] ? self::laravelDefault() : new self($map);
    }

    /**
     * Absolute path of the file that would hold $fqcn, or null when none exists.
     */
    public function locate(string $appRoot, string $fqcn): ?string
    {
        $fqcn = Str::ltrim($fqcn, '\\');
        if ($fqcn === '') {
            return null;
        }

        $root = Str::rtrim($appRoot, '/\\');

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
