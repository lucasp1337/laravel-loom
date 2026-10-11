<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Laravel\Mcp\Request;

/**
 * An MCP request that skips validation, so tools run outside a booted
 * application. The documented calls are all well-formed.
 */
final class UnvalidatedRequest extends Request
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $messages
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = [], array $attributes = []): array
    {
        return $this->all();
    }
}
