<?php

declare(strict_types=1);

/**
 * Prevents stale `docs/.../*.md` references in source, docs, and root
 * markdown files from rotting. A reference is any path matching
 * `docs/<segments>/<name>.md` that appears in a tracked file; the
 * referenced file must exist on disk.
 *
 * Scope:
 * - src/**.php (PHPDoc + inline comments)
 * - docs/**.md (cross-doc links)
 * - root markdown: AGENTS.md, README.md, CLAUDE.md, CHANGELOG.md
 *
 * Markdown-link wrappers (`[text](docs/foo.md)`), trailing `#anchor`,
 * trailing `§N`, and trailing punctuation are stripped before checking.
 */
function repoRoot(): string
{
    return dirname(__DIR__, 1).'/..';
}

/**
 * @return list<string>
 */
function collectTrackedFiles(): array
{
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        return [];
    }

    $patterns = [
        $root.'/src',
        $root.'/docs',
    ];
    $rootFiles = ['AGENTS.md', 'README.md', 'CLAUDE.md', 'CHANGELOG.md'];

    $files = [];
    foreach ($patterns as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isFile()) {
                continue;
            }
            $ext = strtolower($entry->getExtension());
            if ($ext !== 'php' && $ext !== 'md') {
                continue;
            }
            $files[] = $entry->getPathname();
        }
    }
    foreach ($rootFiles as $name) {
        $abs = $root.'/'.$name;
        if (is_file($abs)) {
            $files[] = $abs;
        }
    }

    return $files;
}

it('forbids § section anchors in src/ — docs use named headings, not numbers', function () {
    $root = realpath(dirname(__DIR__, 2));
    expect($root)->not->toBeFalse();

    $offenders = [];
    foreach (collectTrackedFiles() as $file) {
        if (! str_starts_with($file, $root.'/src/')) {
            continue;
        }
        $contents = file_get_contents($file);
        if ($contents === false || ! str_contains($contents, '§')) {
            continue;
        }
        foreach (explode("\n", $contents) as $lineno => $line) {
            if (str_contains($line, '§')) {
                $rel = str_replace($root.'/', '', $file);
                $offenders[] = "$rel:".($lineno + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "Section-anchor references (§N) found in src/. Docs use named\nheadings, not numbered sections — rename or remove these anchors:\n  ".implode("\n  ", $offenders));
});

it('every docs/*.md reference in tracked files points at a real file', function () {
    $root = realpath(dirname(__DIR__, 2));
    expect($root)->not->toBeFalse();

    $missing = [];

    foreach (collectTrackedFiles() as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        // Match `docs/<segments>/<name>.md` — accept letters, digits,
        // dashes, underscores, dots, and slashes inside path segments.
        if (! preg_match_all('#docs/[A-Za-z0-9_./-]+\.md#', $contents, $matches)) {
            continue;
        }

        foreach ($matches[0] as $ref) {
            $clean = rtrim($ref, '.,;:)\'"');
            $abs = $root.'/'.$clean;
            if (! is_file($abs)) {
                $relSource = str_replace($root.'/', '', $file);
                $missing[] = "$relSource → $clean";
            }
        }
    }

    expect($missing)->toBe([], "Dangling docs references:\n  ".implode("\n  ", $missing));
});

it('every relative markdown link resolves from its own directory', function () {
    $root = realpath(dirname(__DIR__, 2));
    expect($root)->not->toBeFalse();

    $files = [];
    foreach (['README.md', 'CONTRIBUTING.md', 'AGENTS.md', 'CLAUDE.md', 'CHANGELOG.md', 'benchmarks/README.md'] as $name) {
        if (is_file($root.'/'.$name)) {
            $files[] = $root.'/'.$name;
        }
    }
    foreach (['docs', '.github'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'md') {
                $files[] = $entry->getPathname();
            }
        }
    }

    $broken = [];
    foreach ($files as $file) {
        // Fenced and inline code are examples, not links.
        $prose = (string) preg_replace(['/```.*?```/s', '/`[^`\n]*`/'], '', (string) file_get_contents($file));
        preg_match_all('/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $prose, $matches);
        foreach ($matches[1] as $target) {
            if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $target)) {
                continue;
            }
            $path = (string) preg_replace('/[#?].*$/', '', $target);
            if ($path === '') {
                continue;
            }
            $abs = str_starts_with($path, '/') ? $root.$path : dirname($file).'/'.$path;
            if (! file_exists($abs)) {
                $broken[] = str_replace($root.'/', '', $file)." → $target";
            }
        }
    }

    expect($broken)->toBe([], "Dangling relative links:\n  ".implode("\n  ", $broken));
});
