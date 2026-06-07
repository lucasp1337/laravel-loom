<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * An HTTP route: its verb, URI, name, controller target, middleware, and what
 * the action dispatches. Read model for the `routes` section.
 */
final readonly class Route
{
    /**
     * @param  list<string>  $middleware
     * @param  list<Dispatch>  $dispatches
     */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public ?string $controllerFqcn,
        public ?string $controllerMethod,
        public array $middleware,
        public string $file,
        public int $line,
        public array $dispatches,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            method: Hydrate::string($data, Field::METHOD),
            uri: Hydrate::string($data, Field::URI),
            name: Hydrate::nullableString($data, Field::NAME),
            controllerFqcn: Hydrate::nullableString($data, Field::CONTROLLER_FQCN),
            controllerMethod: Hydrate::nullableString($data, Field::CONTROLLER_METHOD),
            middleware: Hydrate::stringList($data, Field::MIDDLEWARE),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            dispatches: Hydrate::list($data, Field::DISPATCHES, Dispatch::fromArray(...)),
        );
    }
}
