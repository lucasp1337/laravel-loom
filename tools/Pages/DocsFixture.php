<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Query\SnapshotIndexSource;
use Lucasp\Loom\Scanners\DefaultScanners;
use ReflectionClass;
use RuntimeException;

/**
 * Scans the small order app under tests/Fixtures/docs-order-app and runs the
 * real MCP tool classes against that index, so documented responses are
 * produced, never typed by hand.
 */
final class DocsFixture
{
    public const APP = 'tests/Fixtures/docs-order-app';

    private ?IndexQuery $query = null;

    private ?IndexRepository $repository = null;

    private ?string $snapshot = null;

    public function __construct(private readonly string $root) {}

    public function __destruct()
    {
        if ($this->snapshot !== null && is_file($this->snapshot)) {
            unlink($this->snapshot);
        }
    }

    /**
     * Runs one tool call.
     *
     * @param  class-string<Tool>  $class
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, error: bool}
     */
    public function call(string $class, array $arguments): array
    {
        $this->boot();
        $constructor = (new ReflectionClass($class))->getConstructor();
        $dependency = $constructor?->getParameters()[0]->getType();
        $tool = match ((string) $dependency) {
            IndexQuery::class => new $class($this->query),
            IndexRepository::class => new $class($this->repository),
            default => throw new RuntimeException("Unsupported dependency for {$class}"),
        };

        if (! method_exists($tool, 'handle')) {
            throw new RuntimeException("{$class} has no handle method");
        }
        $response = $tool->handle(new UnvalidatedRequest($arguments));
        if (! $response instanceof Response) {
            throw new RuntimeException("{$class} did not return a response");
        }

        return ['text' => (string) $response->content(), 'error' => $response->isError()];
    }

    /**
     * Input schema of a tool, as an MCP client sees it.
     *
     * @param  class-string<Tool>  $class
     * @return array<string, Type>
     */
    public function inputs(string $class): array
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return $tool->schema(new JsonSchemaTypeFactory);
    }

    private function boot(): void
    {
        if ($this->query !== null) {
            return;
        }

        $builder = new IndexBuilder;
        DefaultScanners::registerOn($builder);
        $index = $builder->build($this->root.'/'.self::APP, 'unknown');

        $path = tempnam(sys_get_temp_dir(), 'loom-docs-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary index file');
        }
        file_put_contents($path, json_encode($index->toArray(), JSON_THROW_ON_ERROR));

        $loader = new IndexLoader;
        $this->query = new IndexQuery(new SnapshotIndexSource($loader, $path));
        $this->repository = new IndexRepository($loader, new class implements Kernel
        {
            public function bootstrap(): void {}

            public function handle($input, $output = null): int
            {
                return 0;
            }

            /** @param  array<string, mixed>  $parameters */
            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                return 0;
            }

            /** @param  array<string, mixed>  $parameters */
            public function queue($command, array $parameters = [])
            {
                throw new RuntimeException('The docs fixture never queues commands');
            }

            public function all(): array
            {
                return [];
            }

            public function output(): string
            {
                return '';
            }

            public function terminate($input, $status): void {}
        }, $path);
        $this->repository->disableAutoScan();
        $this->snapshot = $path;
    }
}
