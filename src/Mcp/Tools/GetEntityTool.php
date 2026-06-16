<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Mcp\IndexRepository;

#[Name('get-entity')]
#[Description('Fetch a single entity by kind (event, listener, observer, job, mailable, notification) and fully-qualified class name, returning its full read-model record including dispatch sites and handler chains where applicable.')]
final class GetEntityTool extends Tool
{
    /** @var list<string> */
    private const KINDS = ['event', 'listener', 'observer', 'job', 'mailable', 'notification'];

    public function __construct(private readonly IndexRepository $repository)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(self::KINDS)
                ->description('The kind of entity to fetch.')
                ->required(),
            'fqcn' => $schema->string()
                ->description('The fully-qualified class name of the entity.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => 'required|string',
            'fqcn' => 'required|string',
        ]);

        $kind = $validated['kind'];
        $fqcn = $validated['fqcn'];

        if (! in_array($kind, self::KINDS, true)) {
            return Response::error("Unknown kind [{$kind}].");
        }

        $entity = $this->resolve($this->repository->index(), $kind, $fqcn);

        if ($entity === null) {
            return Response::error("No {$kind} found for {$fqcn}.");
        }

        return Response::text((string) json_encode([
            'kind' => $kind,
            'fqcn' => $fqcn,
            'found' => true,
            'entity' => $entity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function resolve(Index $index, string $kind, string $fqcn): ?object
    {
        return match ($kind) {
            'event' => $index->findEvent($fqcn),
            'listener' => $index->findListener($fqcn),
            'observer' => $index->findObserver($fqcn),
            'job' => $index->findJob($fqcn),
            'mailable' => $index->findMailable($fqcn),
            'notification' => $index->findNotification($fqcn),
            default => null,
        };
    }
}
