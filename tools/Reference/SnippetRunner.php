<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Runs snippets in order from a scratch directory that holds an index at
 * `storage/loom/index.json`, the path the snippets load. Snippets of one run
 * share a scope, so a later one uses what an earlier one set.
 */
final class SnippetRunner
{
    /**
     * @param  list<Snippet>  $snippets
     * @return array<string, string> snippet path => printed output
     */
    public static function run(string $root, string $indexPath, array $snippets): array
    {
        $files = new Filesystem;
        $workdir = sys_get_temp_dir().'/loom-snippets-'.bin2hex(random_bytes(6));
        $files->ensureDirectoryExists($workdir.'/storage/loom');
        $files->copy($indexPath, $workdir.'/storage/loom/index.json');
        $previous = (string) getcwd();
        chdir($workdir);

        try {
            $scope = [];
            $outputs = [];
            foreach ($snippets as $snippet) {
                [$outputs[$snippet->path()], $scope] = self::runOne($root.'/'.$snippet->path(), $scope);
            }

            return $outputs;
        } finally {
            chdir($previous);
            $files->deleteDirectory($workdir);
        }
    }

    /** A snippet's code and what it printed, as a php fence and an output fence. */
    public static function markdown(string $root, Snippet $snippet, string $output): string
    {
        $code = Str::of((string) file_get_contents($root.'/'.$snippet->path()))->after('<?php')->trim();

        return "```php\n{$code}\n```\n\n```text title=\"Output\"\n".trim($output)."\n```";
    }

    /**
     * Runs one snippet with the variables earlier ones left behind.
     *
     * @param  array<string, mixed>  $__scope
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function runOne(string $__file, array $__scope): array
    {
        extract($__scope);
        ob_start();
        try {
            require $__file;
        } finally {
            $__output = (string) ob_get_clean();
        }

        return [$__output, array_diff_key(get_defined_vars(), array_flip(['__file', '__scope', '__output']))];
    }
}
