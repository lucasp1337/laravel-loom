<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Lucasp\Loom\Index\UnresolvedReason;

/** One code shape Loom reports under a reason, with the change that makes it readable. */
final readonly class UnreadForm
{
    /**
     * @param  string  $code  the line or lines as a reader would write them
     * @param  list<string>  $imports  `use` lines the snippet needs besides the facades
     * @param  array<string, string>  $files  other files the scan needs, path under the app root => PHP source
     */
    public function __construct(
        public UnresolvedReason $reason,
        public FormContext $context,
        public string $code,
        public string $fix,
        public array $imports = [],
        public array $files = [],
    ) {}

    /** The `use` lines, one per import. */
    public function importLines(): string
    {
        return collect($this->imports)->map(static fn (string $import): string => "use {$import};")->implode("\n");
    }

    /** What the page shows: imports, then the code. */
    public function display(): string
    {
        return trim($this->importLines()."\n\n".$this->code);
    }
}
