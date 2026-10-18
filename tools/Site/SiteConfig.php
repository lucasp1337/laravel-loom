<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Site;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the generated site files need from `mkdocs.yml`: the site name,
 * description and URL, and the nav pages in order. Read line by line; the nav
 * is a list of `- Title: path.md` entries nested under top-level sections.
 */
final readonly class SiteConfig
{
    public const FILE = 'mkdocs.yml';

    /** Section of the pages listed at the top level of the nav, outside any section. */
    public const TOP_LEVEL = 'Start';

    /**
     * @param  list<NavPage>  $pages
     */
    private function __construct(
        public string $name,
        public string $description,
        public string $url,
        public array $pages,
    ) {}

    public static function read(string $root): self
    {
        $yaml = (string) file_get_contents($root.'/'.self::FILE);

        return new self(self::scalar($yaml, 'site_name'), self::scalar($yaml, 'site_description'), self::scalar($yaml, 'site_url'), self::nav($yaml));
    }

    private static function scalar(string $yaml, string $key): string
    {
        return preg_match('/^'.$key.':\s*(.+)$/m', $yaml, $match) === 1
            ? trim($match[1])
            : throw new RuntimeException(self::FILE." has no {$key}");
    }

    /** @return list<NavPage> */
    private static function nav(string $yaml): array
    {
        $section = self::TOP_LEVEL;
        $pages = [];
        foreach (explode("\n", Str::of($yaml)->after("\nnav:\n")->value()) as $line) {
            if (preg_match('/^( *)- (?:([^:]+):)?\s*(\S+\.md)?\s*$/', $line, $entry) !== 1) {
                // The nav ends at the first line that is not a list entry.
                break;
            }
            $topLevel = strlen($entry[1]) === 2;
            $path = $entry[3] ?? '';
            match (true) {
                // A top-level section: the pages under it belong to it.
                $topLevel && $path === '' => $section = trim($entry[2] ?? ''),
                // A top-level page outside any section.
                $topLevel => $pages[] = new NavPage(self::TOP_LEVEL, $path),
                // A page inside the current section.
                $path !== '' => $pages[] = new NavPage($section, $path),
                // A nested group heading adds no page.
                default => null,
            };
        }

        return $pages;
    }
}
