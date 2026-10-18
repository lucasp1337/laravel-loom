<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Illuminate\Filesystem\Filesystem;

/**
 * Runs the PHP API snippets in page order from a scratch directory that holds
 * an index at `storage/loom/index.json`, the path the snippets load.
 */
final class ApiSnippetRunner
{
    /** @return array<string, string> snippet value => printed output */
    public static function run(string $root, string $indexPath): array
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
            foreach (ApiSnippet::cases() as $snippet) {
                [$outputs[$snippet->value], $scope] = self::runOne($root.'/'.$snippet->path(), $scope);
            }

            return $outputs;
        } finally {
            chdir($previous);
            $files->deleteDirectory($workdir);
        }
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
