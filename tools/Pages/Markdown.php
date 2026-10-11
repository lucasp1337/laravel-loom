<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;

/** Small markdown builders shared by the page sources. */
final class Markdown
{
    /** Table cell text: pipes escaped, newlines as line breaks. */
    public static function cell(string $text): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\|', '<br>', '<br>'], trim($text));
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows  already-formatted cells
     */
    public static function table(array $header, array $rows): string
    {
        $lines = [
            '| '.implode(' | ', $header).' |',
            '| '.implode(' | ', Arr::map($header, static fn (string $h): string => '---')).' |',
        ];
        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines);
    }

    /** @param  list<string>  $values */
    public static function codeList(array $values): string
    {
        return implode(', ', Arr::map($values, static fn (string $v): string => "`{$v}`"));
    }

    /** Heading slug the way Python-Markdown's toc extension computes it. */
    public static function slug(string $heading): string
    {
        $text = str_replace(['`', '*'], '', $heading);
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^\w\s-]/', '', $text);

        return (string) preg_replace('/[-\s]+/', '-', strtolower(trim($text)));
    }
}
