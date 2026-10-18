<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

use Illuminate\Support\Str;

/** One app file inside a case: its path under the app root and its lines. */
final readonly class ExampleFile
{
    /**
     * @param  int  $firstLine  the case-file line the body starts on
     * @param  list<string>  $lines  the body, as written in the case
     */
    public function __construct(
        public string $path,
        public int $firstLine,
        public array $lines,
    ) {}

    /** The file as scanned, padded so every line keeps its case-file number. */
    public function source(): string
    {
        return '<?php'.str_repeat("\n", $this->firstLine - 1).implode("\n", $this->lines)."\n";
    }

    /** The body as shown on the page. */
    public function display(): string
    {
        return Str::of(implode("\n", $this->lines))->trim("\n")->toString();
    }
}
