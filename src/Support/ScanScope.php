<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use FilesystemIterator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Which parts of the app a scan covers: the scan directories (relative to
 * the app root, `*` globs allowed), the route directories and the exclude globs. Primitive
 * directories (`Jobs`, `Listeners`, ...) resolve inside each scan directory.
 *
 * @internal
 */
final class ScanScope
{
    public const DEFAULT_PATH = 'app';

    public const DEFAULT_ROUTE_PATH = 'routes';

    /** @var list<string> */
    private array $paths;

    /** @var list<string> */
    private array $routePaths;

    /** @var array<string, list<string>> */
    private array $resolved = [];

    /** @var array<string, list<string>> */
    private array $resolvedRoutes = [];

    /** @var list<string> compiled exclude regexes */
    private array $excludes = [];

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $exclude
     * @param  list<string>  $routePaths
     *
     * @throws InvalidArgumentException on a path that is empty, absolute or climbs out of the app root
     */
    public function __construct(array $paths = [self::DEFAULT_PATH], array $exclude = [], array $routePaths = [self::DEFAULT_ROUTE_PATH])
    {
        $normalised = [];
        foreach ($paths as $path) {
            $normalised[] = self::normalisePath($path);
        }
        $this->paths = array_values(array_unique($normalised));

        $this->routePaths = array_values(array_unique(array_map(self::normalisePath(...), $routePaths)));

        foreach ($exclude as $glob) {
            $this->excludes[] = self::compileGlob(self::normaliseGlob($glob));
        }
    }

    public static function default(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $config  the `loom.scan` config array
     * @param  list<string>|null  $pathOverride  replaces `paths` (the `--path` option)
     * @param  list<string>|null  $routePathOverride  replaces `route_paths` (the `--route-path` option)
     */
    public static function fromConfig(array $config, string $appRoot, ?array $pathOverride = null, ?array $routePathOverride = null): self
    {
        $paths = $pathOverride ?? self::stringList($config[ScanConfigKey::PATHS->value] ?? null, [self::DEFAULT_PATH]);

        if (($config[ScanConfigKey::PSR4_PATHS->value] ?? false) === true) {
            $paths = array_merge($paths, ComposerPsr4Map::fromAppRoot($appRoot)->directories());
        }

        $routePaths = $routePathOverride ?? self::stringList($config[ScanConfigKey::ROUTE_PATHS->value] ?? null, [self::DEFAULT_ROUTE_PATH]);

        return new self($paths, self::stringList($config[ScanConfigKey::EXCLUDE->value] ?? null, []), $routePaths);
    }

    /**
     * Existing scan directories, absolute, without duplicates.
     *
     * @return list<string>
     */
    public function directories(string $appRoot): array
    {
        return $this->resolved[$appRoot] ??= $this->resolveDirectories($appRoot, $this->paths);
    }

    /**
     * Existing route directories, absolute, without duplicates.
     *
     * @return list<string>
     */
    public function routeDirectories(string $appRoot): array
    {
        return $this->resolvedRoutes[$appRoot] ??= $this->resolveDirectories($appRoot, $this->routePaths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function resolveDirectories(string $appRoot, array $paths): array
    {
        $root = Str::rtrim($appRoot, '/\\');
        $found = [];

        foreach ($paths as $path) {
            $pattern = $root.'/'.$path;
            $matches = Str::isMatch('/[*?\[]/', $path)
                ? (glob(preg_replace('/[*?\[\]]/', '[$0]', $root).'/'.$path, GLOB_ONLYDIR) ?: [])
                : (is_dir($pattern) ? [$pattern] : []);

            foreach ($matches as $match) {
                $found[Str::replace('/', DIRECTORY_SEPARATOR, $match)] = true;
            }
        }

        $directories = array_keys($found);
        sort($directories);

        return $directories;
    }

    /**
     * PHP files under the scan directories (or their `$subdirectory`),
     * minus excluded files, each yielded once.
     *
     * @return iterable<SplFileInfo>
     */
    public function files(string $appRoot, ?string $subdirectory = null): iterable
    {
        return $this->filesIn($appRoot, $this->directories($appRoot), $subdirectory);
    }

    /**
     * PHP files under the route directories, minus excluded files, each
     * yielded once.
     *
     * @return iterable<SplFileInfo>
     */
    public function routeFiles(string $appRoot): iterable
    {
        return $this->filesIn($appRoot, $this->routeDirectories($appRoot), null);
    }

    /**
     * @param  list<string>  $directories
     * @return iterable<SplFileInfo>
     */
    private function filesIn(string $appRoot, array $directories, ?string $subdirectory): iterable
    {
        $seen = [];

        foreach ($directories as $directory) {
            $target = $subdirectory === null ? $directory : $directory.DIRECTORY_SEPARATOR.$subdirectory;
            if (! is_dir($target)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo || ! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                    continue;
                }

                $absolute = $entry->getPathname();
                if (isset($seen[$absolute]) || $this->isExcluded($appRoot, $absolute)) {
                    continue;
                }
                $seen[$absolute] = true;

                yield $entry;
            }
        }
    }

    /** True when $absolute lies in a scan directory and is not excluded. */
    public function admits(string $appRoot, string $absolute): bool
    {
        if ($this->isExcluded($appRoot, $absolute)) {
            return false;
        }

        foreach ($this->directories($appRoot) as $directory) {
            if (Str::startsWith($absolute, $directory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** True when $absolute lies under `<scan directory>/<subdirectory>/`. */
    public function isUnder(string $appRoot, string $absolute, string $subdirectory): bool
    {
        foreach ($this->directories($appRoot) as $directory) {
            if (Str::startsWith($absolute, $directory.DIRECTORY_SEPARATOR.$subdirectory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An exclude glob matches the file or any directory above it, relative to
     * the app root.
     */
    public function isExcluded(string $appRoot, string $absolute): bool
    {
        if ($this->excludes === []) {
            return false;
        }

        $prefix = Str::rtrim($appRoot, '/\\').DIRECTORY_SEPARATOR;
        if (! Str::startsWith($absolute, $prefix)) {
            return false;
        }

        $relative = Str::replace(DIRECTORY_SEPARATOR, '/', Str::chopStart($absolute, $prefix));
        $candidate = '';
        foreach (explode('/', $relative) as $segment) {
            $candidate = $candidate === '' ? $segment : $candidate.'/'.$segment;
            foreach ($this->excludes as $regex) {
                if (Str::isMatch($regex, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private static function normalisePath(string $path): string
    {
        $path = Str::trim(Str::replace('\\', '/', $path));
        if (Str::startsWith($path, '/') || Str::isMatch('#^[A-Za-z]:#', $path)) {
            throw new InvalidArgumentException("Scan path must be relative to the project root: {$path}");
        }

        $path = preg_replace('#^(\./)+#', '', $path) ?? $path;
        $path = Str::rtrim($path, '/');

        if ($path === '' || $path === '.') {
            throw new InvalidArgumentException('Scan path must not be empty.');
        }
        if (in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Scan path must stay inside the project root: {$path}");
        }

        return $path;
    }

    private static function normaliseGlob(string $glob): string
    {
        $glob = Str::trim(Str::replace('\\', '/', $glob));
        $glob = preg_replace('#^(\./)+#', '', $glob) ?? $glob;

        return Str::trim($glob, '/');
    }

    /** `*` stays within a segment, `**` crosses segments, `?` is one non-slash character. */
    private static function compileGlob(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^'.$regex.'$#';
    }
}
