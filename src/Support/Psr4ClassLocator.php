<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\NodeVisitorAbstract;

/**
 * Locate an FQCN's source file under an app root using a Laravel-default
 * PSR-4 mapping convention (App\ => app/), and (optionally) parse the
 * resulting file to recover a class declaration record.
 *
 * `locate()` is a pure path heuristic: returns the absolute path if a
 * file exists at the guessed location, otherwise null. `locateAndParse()`
 * additionally runs a caller-supplied class visitor and projects the
 * matched declaration into the caller's record shape — the six near-
 * identical `locateByPsr4Guess()` methods that used to live on each
 * scanner now share this method.
 */
final class Psr4ClassLocator
{
    /**
     * Return the absolute file path for the FQCN's PSR-4 guess, or null
     * when no file exists at that path.
     */
    public function locate(string $appRoot, string $fqcn): ?string
    {
        $trimmed = ltrim($fqcn, '\\');
        if ($trimmed === '') {
            return null;
        }

        $segments = explode('\\', $trimmed);

        // Map the leading "App" segment (Laravel default root namespace)
        // to the "app/" directory; leave any other root namespace
        // lowercased.
        $segments[0] = $segments[0] === 'App' ? 'app' : strtolower($segments[0]);

        $relative = implode('/', $segments).'.php';
        $absolute = $appRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    /**
     * Locate $fqcn's source file via {@see self::locate()}, parse it
     * with the given $visitor (which must expose `getClasses(): array`
     * returning records keyed `fqcn`), find the entry matching $fqcn,
     * and project it through $project. Returns null when the file isn't
     * located OR no class with $fqcn is declared in the parsed file.
     *
     * The $project callback receives:
     *   - the matched class record from the visitor
     *   - the relative path to the located file (forward-slashed)
     * and returns the caller's per-scanner result shape.
     *
     * @template T of array
     *
     * @param  callable(array<string, mixed>, string): T  $project
     * @return T|null
     */
    public function locateAndParse(
        string $appRoot,
        string $fqcn,
        AstWalker $walker,
        NodeVisitorAbstract $visitor,
        callable $project,
    ): ?array {
        $absolute = $this->locate($appRoot, $fqcn);
        if ($absolute === null) {
            return null;
        }

        if ($walker->walk($absolute, [$visitor]) === null) {
            return null;
        }

        if (! method_exists($visitor, 'getClasses')) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $classes */
        $classes = $visitor->getClasses();
        foreach ($classes as $class) {
            if (($class['fqcn'] ?? null) === $fqcn) {
                return $project($class, $this->forwardSlashRelative($appRoot, $absolute));
            }
        }

        return null;
    }

    private function forwardSlashRelative(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
    }
}
