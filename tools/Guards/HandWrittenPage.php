<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

use Illuminate\Support\Str;
use Lucasp\Loom\Tools\Regions\Markers;

/**
 * A consumer page as its author wrote it: generated regions are emptied, so
 * everything left is hand-written.
 */
final readonly class HandWrittenPage
{
    private const FENCE = '/^( *)(`{3,})([^\n]*)\n(.*?)^\1\2[ \t]*$/ms';

    /** The page text with every generated region emptied, markers kept, front matter dropped. */
    public string $markdown;

    /** The page text as rendered, generated regions included, front matter dropped. */
    public string $rendered;

    public function __construct(public string $path, string $content)
    {
        $content = (string) preg_replace('/\A---\n.*?\n---\n+/s', '', $content);
        $this->rendered = $content;
        // Cut from the end so earlier offsets stay valid.
        foreach (array_reverse(Markers::pairs($content, $path)) as $pair) {
            $content = substr($content, 0, $pair->bodyStart)."\n".substr($content, $pair->bodyEnd);
        }
        $this->markdown = $content;
    }

    public function kind(): ?PageKind
    {
        return PageKind::of($this->path);
    }

    /** Prose only: no code fences and no HTML comments. */
    public function prose(): string
    {
        return Str::of($this->markdown)
            ->replaceMatches(self::FENCE, '')
            ->replaceMatches('/<!--.*?-->/s', '')
            ->value();
    }

    /** Words of prose, tables and headings included. */
    public function words(): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’_-]*/u', $this->prose());
    }

    /** @return list<Fence> */
    public function fences(): array
    {
        preg_match_all(self::FENCE, $this->markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(function (array $match): Fence {
            $before = Str::of(substr($this->markdown, 0, $match[0][1]))->rtrim()->afterLast("\n")->trim()->value();

            return new Fence(
                language: Str::of($match[3][0])->trim()->before(' ')->value(),
                body: $match[4][0],
                line: substr_count($this->markdown, "\n", 0, $match[0][1]) + 1,
                illustrative: $before === Fence::ILLUSTRATIVE,
            );
        }, $matches);
    }

    /**
     * Headings outside code fences, in order.
     *
     * @return list<Heading>
     */
    public function headings(bool $generated = false): array
    {
        $text = (string) preg_replace(self::FENCE, '', $generated ? $this->rendered : $this->markdown);
        preg_match_all('/^(#{1,6})\s+(.+?)\s*#*$/m', $text, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): Heading => new Heading(strlen($match[1]), $match[2]), $matches);
    }

    /** The first non-blank block after the H1, or null when the page has nothing after it. */
    public function lead(): ?string
    {
        $afterTitle = Str::of($this->markdown)->after("\n")->ltrim();

        return $afterTitle->isEmpty() ? null : $afterTitle->before("\n")->value();
    }
}
