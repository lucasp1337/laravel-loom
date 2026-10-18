<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Check\RuleKey;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\MetaField;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Mcp\Tools\EventsFollowingTool;
use Lucasp\Loom\Mcp\Tools\FindUnresolvedDispatchesTool;
use Lucasp\Loom\Tools\Reference\OutputSample;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command output and index excerpts for the guides, one region per
 * {@see OutputSample}. Commands run against the docs fixture app (base) and
 * the same app with the branch overlay (branch), see {@see DocsApp}.
 */
final class CommandOutputPages implements RegionSource
{
    private const EVENT = 'App\\Events\\OrderPlaced';

    /** Values that differ per run or per install, pinned in the index shape. */
    private const PINNED = [
        MetaField::SCANNED_AT->value => '2026-01-01T00:00:00Z',
        MetaField::LARAVEL_VERSION->value => '13.0',
    ];

    private readonly DocsApp $app;

    public function __construct(private readonly SourceContext $context)
    {
        $this->app = new DocsApp($context->root);
    }

    public function regions(): array
    {
        return array_map(
            fn (OutputSample $sample): GeneratedRegion => new GeneratedRegion(
                RegionName::keyed(RegionPrefix::Output, $sample->value),
                "```{$sample->fence()}\n".$this->sample($sample)."\n```",
            ),
            OutputSample::cases(),
        );
    }

    private function sample(OutputSample $sample): string
    {
        $base = $this->app->base();
        $branch = $this->app->branch();

        return match ($sample) {
            OutputSample::Scan => $this->app->run($base, 'loom:scan'),
            OutputSample::ScanVerbose => $this->app->run($branch, 'loom:scan', [], OutputInterface::VERBOSITY_VERBOSE),
            OutputSample::ShowMissing => $this->app->run($this->app->empty(), 'loom:show'),
            OutputSample::ShowEvent => $this->json($this->entry($this->show(), Sections::EVENTS, Field::ID, self::EVENT)),
            OutputSample::McpNoScan => $this->app->run($this->app->empty(), 'loom:mcp', ['--no-scan' => true]),
            OutputSample::McpEventsFollowing => $this->tool(EventsFollowingTool::class, ['event_fqcn' => self::EVENT]),
            OutputSample::McpUnresolved => $this->tool(FindUnresolvedDispatchesTool::class, []),
            OutputSample::IndexShape => $this->shape(),
            OutputSample::IndexRoute => $this->excerpt(Sections::ROUTES, Field::CONTROLLER_METHOD, 'store', [
                Field::METHOD, Field::URI, Field::NAME, Field::CONTROLLER_FQCN, Field::CONTROLLER_METHOD, Field::FILE, Field::LINE, Field::DISPATCHES,
            ]),
            OutputSample::IndexEvent => $this->excerpt(Sections::EVENTS, Field::ID, self::EVENT, [
                Field::ID, Field::FILE, Field::LINE, Field::DISPATCHED_FROM, Field::HANDLED_BY,
            ]),
            OutputSample::IndexListener => $this->excerpt(Sections::LISTENERS, Field::FQCN, 'App\\Listeners\\SendReceipt', [
                Field::FQCN, Field::FILE, Field::LINE, Field::HANDLES, Field::REGISTRATION, Field::QUEUED, Field::DISPATCHES,
            ]),
            OutputSample::IndexJob => $this->excerpt(Sections::JOBS, Field::FQCN, 'App\\Jobs\\SendMail', [
                Field::FQCN, Field::FILE, Field::LINE, Field::QUEUED, Field::DISPATCHED_FROM,
            ]),
            OutputSample::Check => $this->check([]),
            OutputSample::CheckBaseline => $this->check(['--baseline' => $this->app->scanned($base)]),
            OutputSample::CheckJson => $this->check(['--baseline' => $this->app->scanned($base), '--format' => 'json']),
            OutputSample::CheckSkipped => $this->json($this->skippedRule()),
            OutputSample::CheckMarkdown => $this->check(['--baseline' => $this->app->scanned($base), '--format' => 'markdown']),
            OutputSample::Diff => $this->diff('text'),
            OutputSample::DiffJson => $this->diff('json'),
            OutputSample::DiffMarkdown => $this->diff('markdown'),
        };
    }

    /** @param  array<string, mixed>  $options */
    private function check(array $options): string
    {
        return $this->app->run($this->app->branch(), 'loom:check', ['index' => $this->app->scanned($this->app->branch()), ...$options]);
    }

    private function diff(string $format): string
    {
        return $this->app->run($this->app->branch(), 'loom:diff', [
            'old' => $this->app->scanned($this->app->base()),
            'new' => $this->app->scanned($this->app->branch()),
            '--format' => $format,
        ]);
    }

    /** @return array<string, mixed> the orphan-events rule of a run that skips it */
    private function skippedRule(): array
    {
        $rule = RuleKey::ORPHAN_EVENTS->value;
        $report = $this->decode($this->check(['--format' => 'json', '--skip' => [$rule]]));

        return self::firstWhere($report['rules'] ?? null, 'key', $rule);
    }

    /** @return array<string, mixed> the index `loom:show` prints for the base app */
    private function show(): array
    {
        $this->app->scanned($this->app->base());

        return $this->decode($this->app->run($this->app->base(), 'loom:show', ['filter' => class_basename(self::EVENT)]));
    }

    /** @return array<string, mixed> the base app's index */
    private function index(): array
    {
        return $this->decode((string) file_get_contents($this->app->scanned($this->app->base())));
    }

    /**
     * One entry of the base index, cut down to the fields that carry the links.
     *
     * @param  list<Field>  $fields
     */
    private function excerpt(Sections $section, Field $key, string $value, array $fields): string
    {
        $entry = $this->entry($this->index(), $section, $key, $value);

        return $this->json(Arr::only($entry, array_map(static fn (Field $field): string => $field->value, $fields)));
    }

    /**
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>
     */
    private function entry(array $index, Sections $section, Field $key, string $value): array
    {
        return self::firstWhere($index[$section->value] ?? null, $key->value, $value);
    }

    /** @return array<string, mixed> the first row of a decoded list whose key holds the value */
    private static function firstWhere(mixed $rows, string $key, string $value): array
    {
        $row = Arr::first(is_array($rows) ? $rows : [], static fn (mixed $row): bool => is_array($row) && ($row[$key] ?? null) === $value);

        return is_array($row) ? $row : throw new RuntimeException("No row with {$key} {$value} in the docs fixture output");
    }

    /** Metadata and stats as printed, each section elided to one placeholder entry. */
    private function shape(): string
    {
        $lines = Collection::make($this->index())->map(static function (mixed $value, string $key): string {
            $printed = match (true) {
                // Sections are elided; the schema reference documents their entries.
                Sections::tryFrom($key) !== null => '[ { "...": "..." } ]',
                // Pinned so the page does not change with the clock or the installed framework.
                array_key_exists($key, self::PINNED) => json_encode(self::PINNED[$key], JSON_THROW_ON_ERROR),
                default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            };

            return "  \"{$key}\": {$printed}";
        });

        return "{\n".$lines->implode(",\n")."\n}";
    }

    /**
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $tool, array $arguments): string
    {
        return $this->json($this->decode($this->context->fixture->call($tool, $arguments)['text']));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new RuntimeException('Expected a JSON object');
    }

    /** @param  array<mixed>  $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
