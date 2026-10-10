<?php

declare(strict_types=1);

namespace Lucasp\Loom\Console;

use Illuminate\Console\Command;
use Lucasp\Loom\Diff\Format\FormatterFactory;
use Lucasp\Loom\Diff\Format\UnknownDiffFormatException;
use Lucasp\Loom\Diff\IndexDiffer;
use Lucasp\Loom\Index\IndexLoadException;
use Lucasp\Loom\Index\IndexSchema;

/** @internal */
class DiffCommand extends Command
{
    use ReadsCommandInput;

    protected $signature = 'loom:diff {old : Path to the old index.json} {new : Path to the new index.json} {--format=text : Output format (text|json|markdown)}';

    protected $description = 'Semantically diff two Loom index.json files';

    /**
     * Exit codes are non-standard here and intentionally NOT the Command
     * SUCCESS/FAILURE constants: 0 = no changes, 1 = changes exist (still a
     * successful run, mirroring `git diff --exit-code`), 2 = a real error
     * (bad path, invalid JSON, unknown format).
     */
    public function handle(IndexDiffer $differ, FormatterFactory $formatters): int
    {
        $old = $this->decodeJsonObject($this->stringArg('old'));
        $new = $this->decodeJsonObject($this->stringArg('new'));
        if ($old === null || $new === null) {
            return 2;
        }

        try {
            IndexSchema::assertComparable($old, $new);
        } catch (IndexLoadException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        try {
            $formatter = $formatters->make($this->stringOpt('format'));
        } catch (UnknownDiffFormatException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $result = $differ->diff($old, $new);
        $this->line($formatter->format($result));

        return $result->hasChanges() ? 1 : 0;
    }
}
