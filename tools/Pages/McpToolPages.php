<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Tools\Reference\McpGroup;
use Lucasp\Loom\Tools\Reference\McpToolDocs;
use Lucasp\Loom\Tools\Reference\ToolDoc;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The MCP tool reference: an overview table and one region per tool group.
 * Names and inputs come from the tool classes, responses from running them.
 */
final class McpToolPages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        $tools = $this->tools();
        $this->assertCovered($tools);

        $regions = [new GeneratedRegion(Region::McpOverview, $this->overview($tools))];
        foreach (McpGroup::cases() as $group) {
            $sections = ["## {$group->title()}", $group->blurb()];
            foreach (McpToolDocs::inGroup($group) as $doc) {
                $sections[] = $this->toolSection($tools[$doc->tool], $doc);
            }
            $regions[] = new GeneratedRegion(
                RegionName::keyed(RegionPrefix::McpGroup, $group->value),
                implode("\n\n", $sections),
            );
        }

        return $regions;
    }

    /**
     * Registered tools keyed by class.
     *
     * @return array<class-string<Tool>, array{name: string, inputs: array<string, Type>}>
     */
    private function tools(): array
    {
        $classes = (new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue();
        $tools = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $name = $reflection->getAttributes(Name::class)[0]->newInstance()->value;
            $reflection->getAttributes(Description::class)[0]->newInstance();
            $tools[$class] = ['name' => $name, 'inputs' => $this->context->fixture->inputs($class)];
        }

        return $tools;
    }

    /** @param  array<class-string<Tool>, mixed>  $tools */
    private function assertCovered(array $tools): void
    {
        $registered = array_keys($tools);
        $documented = array_map(static fn (ToolDoc $doc): string => $doc->tool, McpToolDocs::all());
        sort($registered);
        sort($documented);

        if ($registered !== $documented) {
            throw new RuntimeException(McpToolDocs::class.' must document exactly the registered tools: '.implode(', ', $registered));
        }
    }

    /** @param  array<class-string<Tool>, array{name: string, inputs: array<string, Type>}>  $tools */
    private function overview(array $tools): string
    {
        $rows = [];
        foreach (McpGroup::cases() as $group) {
            foreach (McpToolDocs::inGroup($group) as $doc) {
                $name = $tools[$doc->tool]['name'];
                $inputs = array_keys($tools[$doc->tool]['inputs']);
                $rows[] = [
                    "[`{$name}`](#{$name})",
                    Markdown::cell($doc->answers),
                    $inputs === [] ? 'none' : Markdown::codeList($inputs),
                ];
            }
        }

        return Markdown::table(['Tool', 'Answers', 'Inputs'], $rows);
    }

    /** @param  array{name: string, inputs: array<string, Type>}  $tool */
    private function toolSection(array $tool, ToolDoc $doc): string
    {
        $out = ["### `{$tool['name']}`", $doc->summary];

        if ($tool['inputs'] === []) {
            $out[] = 'Takes no input.';
        }
        if ($tool['inputs'] !== []) {
            $out[] = $this->inputTable($tool['inputs']);
        }

        $out[] = $this->callBlock($doc->tool, $doc->example, 'Called with', 'it returns');
        foreach ($doc->variants as $variant) {
            $out[] = $this->callBlock($doc->tool, $variant->args, $variant->label.'. Called with', 'it returns');
        }

        $notes = Arr::map($doc->notes, static fn (string $note): string => "- {$note}");
        if ($notes !== []) {
            $out[] = implode("\n", $notes);
        }

        if ($doc->errors !== []) {
            $rows = array_map(
                fn (array $args): array => [$this->callPhrase($args), '`'.$this->errorText($doc->tool, $args).'`'],
                $doc->errors,
            );
            $out[] = "Errors come back as a text message instead of JSON:\n\n".Markdown::table(['Called with', 'Returns'], $rows);
        }

        return implode("\n\n", $out);
    }

    /** @param  array<string, Type>  $inputs */
    private function inputTable(array $inputs): string
    {
        $required = new ReflectionProperty(Type::class, 'required');
        $rows = [];
        foreach ($inputs as $key => $type) {
            $array = $type->toArray();
            $notes = (string) ($array['description'] ?? '');
            if (isset($array['enum'])) {
                $notes = rtrim($notes, '.').'. One of '.Markdown::codeList($array['enum']).'.';
            }
            $rows[] = [
                "`{$key}`",
                (string) ($array['type'] ?? 'any'),
                $required->getValue($type) === true ? 'required' : (array_key_exists('default', $array) ? '`'.(is_string($array['default']) ? $array['default'] : json_encode($array['default'], JSON_THROW_ON_ERROR)).'`' : 'optional'),
                Markdown::cell($notes),
            ];
        }

        return Markdown::table(['Input', 'Type', 'Required / default', 'Notes'], $rows);
    }

    /**
     * @param  class-string<Tool>  $class
     * @param  array<string, mixed>  $args
     */
    private function callBlock(string $class, array $args, string $lead, string $verb): string
    {
        $result = $this->context->fixture->call($class, $args);
        if ($result['error']) {
            throw new RuntimeException("{$class} errored for a documented example: {$result['text']}");
        }
        $phrase = $args === [] ? 'no input' : $this->callPhrase($args);
        $intro = "{$lead} {$phrase}, {$verb}";

        return $intro.":\n\n```json\n".$this->prettyJson($result['text'])."\n```";
    }

    /**
     * @param  class-string<Tool>  $class
     * @param  array<string, mixed>  $args
     */
    private function errorText(string $class, array $args): string
    {
        $result = $this->context->fixture->call($class, $args);
        if (! $result['error']) {
            throw new RuntimeException("{$class} did not error for a documented failing call");
        }

        return $result['text'];
    }

    /** @param  array<string, mixed>  $args */
    private function callPhrase(array $args): string
    {
        return implode(', ', Arr::map(
            array_keys($args),
            static fn (string $key): string => '`'.$key.'` = `'.(is_string($args[$key]) ? ($args[$key] === '' ? '""' : $args[$key]) : json_encode($args[$key], JSON_THROW_ON_ERROR)).'`',
        ));
    }

    private function prettyJson(string $json): string
    {
        $pretty = json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Two-space indentation reads better than PHP's four.
        return (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $pretty);
    }
}
