<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools;

use Lucasp\Loom\Tools\Pages\RegionSource;
use Lucasp\Loom\Tools\Regions\Markers;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Site\LlmsText;
use Lucasp\Loom\Tools\Site\SiteConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Fills the generated regions of the docs. Sources produce page-independent
 * regions; every `docs/**` markdown page and the README that carries a marker
 * pair for a region gets that region's text between the markers. Only text between a
 * pair of markers is ever rewritten. With a site config at the root, the
 * whole-file {@see LlmsText} outputs are written from the rendered pages too.
 */
final class ReferencePages
{
    /** Pages outside the docs directory that may carry regions. */
    private const ROOT_PAGES = ['README.md'];

    /** @var list<RegionSource> */
    private readonly array $sources;

    private readonly ?LlmsText $llms;

    /**
     * @param  list<RegionSource>|null  $sources  defaults to every discovered source
     */
    public function __construct(
        private readonly string $root,
        ?array $sources = null,
        private readonly string $docsDir = 'docs',
    ) {
        $this->sources = $sources ?? SourceRegistry::discover(new SourceContext($root));
        $this->llms = is_file($root.'/'.SiteConfig::FILE) ? new LlmsText($root, $docsDir) : null;
    }

    /**
     * Every page that carries markers, with its regions regenerated, keyed by
     * path relative to the root.
     *
     * @return array<string, string>
     */
    public function render(): array
    {
        $regions = $this->regions();
        $used = [];
        $pages = [];

        foreach ($this->markdownPages() as $page) {
            $content = $this->read($page);
            $pairs = Markers::pairs($content, $page);
            if ($pairs === []) {
                continue;
            }

            // Splice from the end so earlier offsets stay valid.
            foreach (array_reverse($pairs) as $pair) {
                $body = $regions[$pair->name->value] ?? throw new RuntimeException(
                    "{$page} places region {$pair->name} but no source produces it",
                );
                $used[$pair->name->value] = true;
                $content = substr($content, 0, $pair->bodyStart)
                    ."\n".Markers::notice()."\n\n".trim($body)."\n"
                    .substr($content, $pair->bodyEnd);
            }
            $pages[$page] = $content;
        }

        $unplaced = array_keys(array_diff_key($regions, $used));
        if ($unplaced !== []) {
            $names = array_map(static fn (string $name): string => Markers::open(RegionName::fromString($name)), $unplaced);

            throw new RuntimeException('Regions produced but placed on no page; add a marker pair for each: '.implode(', ', $names));
        }

        return $pages;
    }

    /**
     * The rendered pages and the whole generated files, keyed by path.
     *
     * @return array<string, string>
     */
    public function outputs(): array
    {
        $pages = $this->render();

        return [...$pages, ...($this->llms?->files($pages) ?? [])];
    }

    /**
     * Pages and files whose committed text differs from a fresh render.
     *
     * @return list<string>
     */
    public function drifted(): array
    {
        $drifted = [];
        foreach ($this->outputs() as $page => $content) {
            if ($content !== $this->read($page)) {
                $drifted[] = $page;
            }
        }

        return $drifted;
    }

    /**
     * Writes every page and file whose generated text differs; returns the changed paths.
     *
     * @return list<string>
     */
    public function write(): array
    {
        $changed = [];
        foreach ($this->outputs() as $page => $content) {
            if ($content !== $this->read($page)) {
                file_put_contents($this->root.'/'.$page, $content);
                $changed[] = $page;
            }
        }

        return $changed;
    }

    /** @return array<string, string> region name => markdown */
    private function regions(): array
    {
        $regions = [];
        foreach ($this->sources as $source) {
            foreach ($source->regions() as $region) {
                if (isset($regions[$region->name->value])) {
                    throw new RuntimeException('Region '.$region->name.' is produced by more than one source');
                }
                $regions[$region->name->value] = $region->body;
            }
        }

        return $regions;
    }

    /** @return list<string> the root pages and the markdown paths under the docs directory, relative to the root */
    private function markdownPages(): array
    {
        $directory = $this->root.'/'.$this->docsDir;
        if (! is_dir($directory)) {
            throw new RuntimeException("Missing docs directory {$this->docsDir}");
        }

        $pages = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $pages[] = $this->docsDir.substr($file->getPathname(), strlen($directory));
            }
        }
        sort($pages);

        return [...array_filter(self::ROOT_PAGES, fn (string $page): bool => is_file($this->root.'/'.$page)), ...$pages];
    }

    /** A page's committed text; empty for a generated file not written yet. */
    private function read(string $page): string
    {
        return is_file($this->root.'/'.$page) ? (string) file_get_contents($this->root.'/'.$page) : '';
    }
}
