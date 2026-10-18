<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Site;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The site's `llms.txt` and `llms-full.txt` (llmstxt.org): the title, the
 * site description as a summary, then one section of links per nav section,
 * each link described by the page's lead. The contributor pages go under
 * `Optional`. The full file is every other page's markdown, in nav order.
 */
final readonly class LlmsText
{
    public const INDEX = 'llms.txt';

    public const FULL = 'llms-full.txt';

    /** The nav section of contributor pages, listed as optional context. */
    private const CONTRIBUTING = 'Contributing';

    private const OPTIONAL = 'Optional';

    public function __construct(
        private string $root,
        private string $docsDir = 'docs',
    ) {}

    /**
     * Both files, keyed by path relative to the root.
     *
     * @param  array<string, string>  $rendered  freshly rendered pages by path; any other page is read from disk
     * @return array<string, string>
     */
    public function files(array $rendered): array
    {
        $site = SiteConfig::read($this->root);
        $pages = collect($site->pages)->mapWithKeys(fn (NavPage $page): array => [
            $page->path => self::clean($rendered[$this->docsDir.'/'.$page->path] ?? (string) file_get_contents($this->root.'/'.$this->docsDir.'/'.$page->path)),
        ]);
        $header = "# {$site->name}\n\n> {$site->description}\n";

        return [
            $this->docsDir.'/'.self::INDEX => $header."\n".$this->sections($site, $pages)."\n",
            $this->docsDir.'/'.self::FULL => $header."\n".$pages
                ->filter(static fn (string $markdown, string $path): bool => self::sectionOf($site, $path) !== self::OPTIONAL)
                ->implode("\n\n")."\n",
        ];
    }

    /** @param  Collection<string, string>  $pages */
    private function sections(SiteConfig $site, Collection $pages): string
    {
        return collect($site->pages)
            ->groupBy(static fn (NavPage $page): string => self::sectionOf($site, $page->path))
            ->map(static fn (Collection $section, string $title): string => "## {$title}\n\n".$section
                ->map(static fn (NavPage $page): string => self::link($page, $site, $pages->get($page->path, '')))
                ->implode("\n"))
            ->implode("\n\n");
    }

    private static function link(NavPage $page, SiteConfig $site, string $markdown): string
    {
        $title = Str::of($markdown)->match('/^# (.+)$/m')->value();
        $lead = self::lead($markdown);

        return "- [{$title}]({$page->url($site->url)})".($lead === '' ? '' : ": {$lead}");
    }

    /** The first sentence of the paragraph right under the H1, links reduced to their text; empty when the H1 is followed by anything else. */
    private static function lead(string $markdown): string
    {
        $first = Str::of($markdown)->after("\n")->ltrim()->before("\n");
        $isParagraph = $first->isNotEmpty() && ! $first->startsWith(['#', '`', '!', '?', '|', '-', '<', '>']);

        return $isParagraph
            ? (string) $first->replaceMatches('/\[([^\]]+)\]\([^)]+\)/', '$1')->split('/(?<=\.)\s+/')->first()
            : '';
    }

    private static function sectionOf(SiteConfig $site, string $path): string
    {
        $section = collect($site->pages)->first(static fn (NavPage $page): bool => $page->path === $path)?->section;

        return $section === self::CONTRIBUTING ? self::OPTIONAL : (string) $section;
    }

    /** Page markdown without front matter or HTML comments, which carry only build and generator notes. */
    private static function clean(string $markdown): string
    {
        return Str::of($markdown)
            ->replaceMatches('/\A---\n.*?\n---\n+/s', '')
            ->replaceMatches('/<!--.*?-->\n?/s', '')
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->value();
    }
}
