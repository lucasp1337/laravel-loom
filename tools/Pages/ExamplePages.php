<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Tools\Examples\ExampleCase;
use Lucasp\Loom\Tools\Examples\ExampleCorpus;
use Lucasp\Loom\Tools\Examples\ExampleFile;
use Lucasp\Loom\Tools\Examples\Primitive;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;

/**
 * The detection pages: one region per primitive with each case of
 * `docs/examples/` as a collapsible example, and an index region with the
 * primitive pages and the page that shows each unresolved reason.
 */
final class ExamplePages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        ExampleCorpus::assertKnownDirectories($this->context->root);

        $cases = collect(Primitive::cases())->mapWithKeys(fn (Primitive $primitive): array => [
            $primitive->value => ExampleCorpus::for($this->context->root, $primitive),
        ]);

        $pages = collect(Primitive::cases())->map(fn (Primitive $primitive): GeneratedRegion => new GeneratedRegion(
            RegionName::keyed(RegionPrefix::Examples, $primitive->value),
            collect($cases->get($primitive->value))->map(fn (ExampleCase $case): string => $this->example($case))->implode("\n\n"),
        ));

        return [...$pages->all(), new GeneratedRegion(Region::Examples, $this->index($cases))];
    }

    private function example(ExampleCase $case): string
    {
        $files = collect($case->files)->map(static fn (ExampleFile $file): string => "```php title=\"{$file->path}\"\n{$file->display()}\n```");
        $body = Str::of($files->implode("\n\n"))->explode("\n")->map(static fn (string $line): string => $line === '' ? '' : "    {$line}")->implode("\n");

        return '??? example "'.str_replace('"', "'", $case->title)."\"\n\n{$body}";
    }

    /** @param  Collection<string, list<ExampleCase>>  $cases */
    private function index(Collection $cases): string
    {
        $links = collect(Primitive::cases())->map(static fn (Primitive $primitive): string => '- '.self::link($primitive))->implode("\n");

        $reasons = array_values(collect(UnresolvedReason::cases())->merge(UnresolvedRouteReason::cases())
            ->map(static fn (UnresolvedReason|UnresolvedRouteReason $reason): array => [
                "`{$reason->value}`",
                collect(Primitive::cases())
                    ->filter(static fn (Primitive $primitive): bool => collect($cases->get($primitive->value))->contains(
                        static fn (ExampleCase $case): bool => $case->reasons()->contains($reason->value),
                    ))
                    ->map(self::link(...))
                    ->implode(', '),
            ])
            ->all());

        return "{$links}\n\n## Unresolved reasons\n\nEach `reason` in `unresolved_dispatches` and `unresolved_routes`, and the page that shows it.\n\n"
            .Markdown::table(['Reason', 'Shown on'], $reasons);
    }

    private static function link(Primitive $primitive): string
    {
        return "[{$primitive->title()}](".basename($primitive->page()).')';
    }
}
