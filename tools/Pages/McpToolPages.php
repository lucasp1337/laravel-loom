<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Mcp\LoomMcpServer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The MCP tool reference: an overview table and one region per tool group.
 * Names and inputs come from the tool classes, responses from running them.
 */
final class McpToolPages implements RegionSource
{
    private const PAGE = 'docs/reference/mcp-tools.md';

    public function __construct(
        private readonly string $root,
        private readonly DocsFixture $fixture,
    ) {}

    public function regions(): array
    {
        $human = require $this->root.'/tools/reference/mcp-tools.php';
        $tools = $this->tools();
        $this->assertCovered($tools, $human);

        $regions = ['mcp:overview' => $this->overview($tools, $human)];
        foreach ($human['groups'] as $key => $group) {
            $sections = ["## {$group['title']}", $group['blurb']];
            foreach ($group['tools'] as $name) {
                $sections[] = $this->toolSection($name, $tools[$name], $human['tools'][$name]);
            }
            $regions["mcp:{$key}"] = implode("\n\n", $sections);
        }

        return Arr::map($regions, static fn (string $body): array => ['page' => self::PAGE, 'body' => $body]);
    }

    /**
     * @return array<string, array{class: class-string<Tool>, inputs: array<string, Type>}>
     */
    private function tools(): array
    {
        $classes = (new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue();
        $tools = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $name = $reflection->getAttributes(Name::class)[0]->newInstance()->value;
            $reflection->getAttributes(Description::class)[0]->newInstance();
            $tools[$name] = ['class' => $class, 'inputs' => $this->fixture->inputs($class)];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $tools
     * @param  array<string, mixed>  $human
     */
    private function assertCovered(array $tools, array $human): void
    {
        $grouped = Arr::flatten(Arr::pluck($human['groups'], 'tools'));
        $expected = array_keys($tools);
        sort($expected);
        $actualGrouped = $grouped;
        sort($actualGrouped);
        $described = array_keys($human['tools']);
        sort($described);

        if ($expected !== $actualGrouped || $expected !== $described) {
            throw new RuntimeException('tools/reference/mcp-tools.php must group and describe exactly the registered tools: '.implode(', ', $expected));
        }
    }

    /**
     * @param  array<string, array{class: class-string<Tool>, inputs: array<string, Type>}>  $tools
     * @param  array<string, mixed>  $human
     */
    private function overview(array $tools, array $human): string
    {
        $rows = [];
        foreach (Arr::flatten(Arr::pluck($human['groups'], 'tools')) as $name) {
            $inputs = array_keys($tools[$name]['inputs']);
            $rows[] = [
                "[`{$name}`](#{$name})",
                Markdown::cell($human['tools'][$name]['answers']),
                $inputs === [] ? 'none' : Markdown::codeList($inputs),
            ];
        }

        return Markdown::table(['Tool', 'Answers', 'Inputs'], $rows);
    }

    /**
     * @param  array{class: class-string<Tool>, inputs: array<string, Type>}  $tool
     * @param  array<string, mixed>  $human
     */
    private function toolSection(string $name, array $tool, array $human): string
    {
        $out = ["### `{$name}`", $human['summary']];

        if ($tool['inputs'] === []) {
            $out[] = 'Takes no input.';
        }
        if ($tool['inputs'] !== []) {
            $out[] = $this->inputTable($tool['inputs']);
        }

        $out[] = $this->callBlock($tool['class'], $human['example'], 'Called with', 'it returns');
        foreach ($human['variants'] ?? [] as $variant) {
            $out[] = $this->callBlock($tool['class'], $variant['args'], $variant['label'].'. Called with', 'it returns');
        }

        $notes = Arr::map($human['notes'] ?? [], static fn (string $note): string => "- {$note}");
        if ($notes !== []) {
            $out[] = implode("\n", $notes);
        }

        if (($human['errors'] ?? []) !== []) {
            $rows = array_values(Arr::map(
                $human['errors'],
                fn (array $args): array => [$this->callPhrase($args), '`'.$this->errorText($tool['class'], $args).'`'],
            ));
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
        $result = $this->fixture->call($class, $args);
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
        $result = $this->fixture->call($class, $args);
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
