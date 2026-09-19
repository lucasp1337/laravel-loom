<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Mcp\ImpactNoteFormatter;
use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\IndexQuery;

#[Name('impact-of-change')]
#[Description('Blast-radius report for removing or renaming a class. For an event: who dispatches it, who handles it (named + closure listeners), and the transitive downstream chain. For a listener/job: which events it handles and whether removing it would orphan an event (an event whose only handler is this class). Notes call out what static analysis cannot determine.')]
final class ImpactOfChangeTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fqcn' => $schema->string()
                ->description('The fully-qualified class name being changed (event, listener, or job).')
                ->required(),
            'kind' => $schema->string()
                ->enum(array_map(static fn (ChangeKind $k): string => $k->value, ChangeKind::cases()))
                ->description('The kind of change. Affects the framing of the notes only.')
                ->default(ChangeKind::REMOVE->value),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'fqcn' => 'required|string',
            'kind' => 'sometimes|string',
        ]);

        $fqcn = (string) $validated['fqcn'];
        $rawKind = $validated['kind'] ?? ChangeKind::REMOVE->value;
        $kind = ChangeKind::tryFrom($rawKind);
        if ($kind === null) {
            $expected = implode(', ', array_map(static fn (ChangeKind $k): string => $k->value, ChangeKind::cases()));

            return Response::error("Unknown kind [{$rawKind}]; expected one of: {$expected}.");
        }

        $report = $this->query->impactOfChange($fqcn, $kind);

        return Response::text((string) json_encode(
            $report->toArray(ImpactNoteFormatter::render(...)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
