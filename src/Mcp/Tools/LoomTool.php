<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use BackedEnum;
use Illuminate\Support\Arr;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared response shaping for the Loom tools: one JSON encoding of a result
 * payload and one wording for an unrecognised enum value.
 *
 * @internal
 */
abstract class LoomTool extends Tool
{
    /**
     * The result as a JSON text response.
     *
     * @param  array<array-key, mixed>  $payload
     */
    protected function json(array $payload): Response
    {
        return Response::text((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The backing values of an enum, for a schema `enum()` list or an error message.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    protected static function enumValues(string $enum): array
    {
        return array_values(Arr::map($enum::cases(), static fn (BackedEnum $case): string => (string) $case->value));
    }

    /**
     * The error for an argument that is not one of the enum's values.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    protected function unknownValue(string $label, string $given, string $enum): Response
    {
        $expected = Arr::join(self::enumValues($enum), ', ');

        return Response::error("Unknown {$label} [{$given}]; expected one of: {$expected}.");
    }
}
