<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Laravel\Mcp\Server\Tool;

/**
 * Hand-written prose for one MCP tool. Its name and inputs come from the tool
 * class; responses and error strings come from running it against the docs
 * fixture, never from here.
 */
final readonly class ToolDoc
{
    /**
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $example  arguments of the main documented call
     * @param  list<string>  $notes
     * @param  list<CallVariant>  $variants
     * @param  list<array<string, mixed>>  $errors  arguments of calls that must fail
     */
    public function __construct(
        public string $tool,
        public McpGroup $group,
        public string $answers,
        public string $summary,
        public array $example,
        public array $notes = [],
        public array $variants = [],
        public array $errors = [],
    ) {}
}
