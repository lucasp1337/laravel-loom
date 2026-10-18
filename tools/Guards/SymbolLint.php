<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Check\RuleKey;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Tools\Reference\ApiClasses;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Application;

/**
 * Finds names in hand-written docs that the code does not have: Artisan
 * commands and their options, config keys, unresolved reasons, MCP tools and
 * Loom classes. Every known set is read from the code. Only tokens shaped like
 * one of those names are checked, so ordinary code spans pass.
 */
final class SymbolLint
{
    /** @var array<string, list<string>> command => option names */
    private readonly array $commands;

    /** @var list<string> dotted config keys, groups included */
    private readonly array $configKeys;

    /** @var list<string> */
    private readonly array $reasons;

    /** @var list<string> snake_case names that are not reasons: schema properties and enum values, config leaves */
    private readonly array $otherSnake;

    /** @var list<string> */
    private readonly array $tools;

    /** @var list<string> */
    private readonly array $ruleKeys;

    /** @var list<string> */
    private readonly array $apiClasses;

    /** @var list<string> */
    private readonly array $srcClasses;

    public function __construct(private readonly string $root)
    {
        $this->commands = $this->readCommands();
        $this->configKeys = self::flatten(require $root.'/config/loom.php');
        $this->reasons = array_values(Arr::map(
            [...UnresolvedReason::cases(), ...UnresolvedRouteReason::cases()],
            static fn (UnresolvedReason|UnresolvedRouteReason $reason): string => $reason->value,
        ));
        $this->otherSnake = array_values($this->schemaNames()->merge(Arr::map($this->configKeys, static fn (string $key): string => Str::afterLast($key, '.')))->unique()->all());
        /** @var list<class-string> $tools */
        $tools = (new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue();
        $this->tools = array_map(
            /** @param  class-string  $tool */
            static fn (string $tool): string => (new ReflectionClass($tool))->getAttributes(Name::class)[0]->newInstance()->value,
            $tools,
        );
        $this->ruleKeys = array_values(Arr::map(RuleKey::cases(), static fn (RuleKey $key): string => $key->value));
        $this->apiClasses = array_values(ApiClasses::api($root)->map(static fn (ReflectionClass $class): string => $class->getName())->all());
        $this->srcClasses = ApiClasses::names($root);
    }

    /** @return list<string> one line per unknown name */
    public function problems(HandWrittenPage $page): array
    {
        preg_match_all('/`([^`\n]+)`/', $page->prose(), $spans);

        $problems = collect($spans[1])->flatMap(fn (string $span): array => $this->checkSpan($span));
        $commandLines = collect($page->fences())
            ->filter(static fn (Fence $fence): bool => in_array($fence->language, ['bash', 'shell', 'sh'], true))
            ->flatMap(static fn (Fence $fence): array => explode("\n", $fence->body))
            ->filter(static fn (string $line): bool => str_contains($line, 'loom:'))
            ->flatMap(fn (string $line): array => $this->checkCommand($line));

        return array_values($problems->merge($commandLines)->unique()->map(static fn (string $problem): string => "{$page->path}: {$problem}")->all());
    }

    /** @return list<string> */
    private function checkSpan(string $span): array
    {
        return match (true) {
            // An Artisan command, maybe with options.
            str_contains($span, 'loom:') => $this->checkCommand($span),
            // A config key such as `scan.paths` or `loom.ui.enabled`.
            preg_match('/^(?:loom\.)?([a-z_]+)\.[a-z_.*]+$/', $span, $config) === 1 && in_array($config[1], $this->configKeys, true) => $this->checkConfig(Str::after($span, 'loom.')),
            // A snake_case name that starts like an unresolved reason.
            preg_match('/^[a-z]+(?:_[a-z]+)+$/', $span) === 1 => $this->checkReason($span),
            // A kebab-case name that starts like an MCP tool.
            preg_match('/^[a-z]+(?:-[a-z]+)+$/', $span) === 1 => $this->checkTool($span),
            // A Loom class, maybe with a member.
            preg_match('/^\\\\?(Lucasp\\\\Loom\\\\[\w\\\\]+|[A-Z][A-Za-z]+)(?:::|->|$)/', $span, $class) === 1 => $this->checkClass($class[1]),
            default => [],
        };
    }

    /** @return list<string> */
    private function checkCommand(string $text): array
    {
        // `loom:*` names the family, not a command.
        if (preg_match('/\b(loom:[a-z-]+)(.*)$/', $text, $match) !== 1) {
            return [];
        }
        $command = $match[1];
        $options = $this->commands[$command] ?? null;
        if ($options === null) {
            return ["unknown command `{$command}`"];
        }
        preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $match[2], $used);

        return array_values(Arr::map(
            array_diff($used[1], $options),
            static fn (string $option): string => "`{$command}` has no option `--{$option}`",
        ));
    }

    /** @return list<string> */
    private function checkConfig(string $key): array
    {
        $known = in_array($key, $this->configKeys, true)
            || (str_ends_with($key, '.*') && in_array(Str::beforeLast($key, '.*'), $this->configKeys, true));

        return $known ? [] : ["unknown config key `{$key}`"];
    }

    /** @return list<string> */
    private function checkReason(string $token): array
    {
        $reasonLike = collect($this->reasons)->contains(static fn (string $reason): bool => Str::before($reason, '_') === Str::before($token, '_'));
        $known = in_array($token, $this->reasons, true) || in_array($token, $this->otherSnake, true);

        return $reasonLike && ! $known ? ["unknown unresolved reason `{$token}`"] : [];
    }

    /** @return list<string> */
    private function checkTool(string $token): array
    {
        $toolLike = collect($this->tools)->contains(static fn (string $tool): bool => Str::before($tool, '-') === Str::before($token, '-'));
        $known = in_array($token, $this->tools, true) || in_array($token, $this->ruleKeys, true);

        return $toolLike && ! $known ? ["unknown MCP tool `{$token}`"] : [];
    }

    /** @return list<string> */
    private function checkClass(string $name): array
    {
        $name = ltrim($name, '\\');

        return match (true) {
            // A fully qualified name: an `@api` class, or a namespace that holds one.
            str_starts_with($name, 'Lucasp\\Loom\\') => in_array($name, $this->apiClasses, true)
                || collect($this->apiClasses)->contains(static fn (string $class): bool => str_starts_with($class, $name.'\\'))
                    ? [] : ["`{$name}` is not an `@api` class"],
            // A short name: only Loom's own internal classes are flagged.
            default => collect($this->srcClasses)->contains(static fn (string $class): bool => class_basename($class) === $name)
                && ! collect($this->apiClasses)->contains(static fn (string $class): bool => class_basename($class) === $name)
                    ? ["`{$name}` is an internal class, not an `@api` one"] : [],
        };
    }

    /** @return array<string, list<string>> */
    private function readCommands(): array
    {
        $global = array_keys((new Application)->getDefinition()->getOptions());

        return collect(glob($this->root.'/src/Console/*Command.php') ?: [])
            ->map(static function (string $file): Command {
                $class = 'Lucasp\\Loom\\Console\\'.basename($file, '.php');
                $command = new $class;

                return $command instanceof Command ? $command : throw new RuntimeException("{$class} is not an Artisan command");
            })
            ->mapWithKeys(static fn (Command $command): array => [
                (string) $command->getName() => [...array_keys($command->getDefinition()->getOptions()), ...$global],
            ])
            ->all();
    }

    /** @return Collection<int, string> property names and string enum values anywhere in the schema */
    private function schemaNames(): Collection
    {
        $schema = json_decode((string) file_get_contents($this->root.'/schema/loom-index.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $names = collect();
        $walk = static function (mixed $node) use (&$walk, $names): void {
            if (! is_array($node)) {
                return;
            }
            $names->push(...array_keys(is_array($node['properties'] ?? null) ? $node['properties'] : []));
            $names->push(...array_filter(is_array($node['enum'] ?? null) ? $node['enum'] : [], is_string(...)));
            array_map($walk, $node);
        };
        $walk($schema);

        return $names->map(static fn (mixed $name): string => (string) $name);
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<string> every key, dotted, groups included
     */
    private static function flatten(array $config, string $prefix = ''): array
    {
        return array_values(collect($config)->flatMap(static function (mixed $value, int|string $key) use ($prefix): array {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $nested = is_array($value) && ! array_is_list($value) ? self::flatten($value, $dotted) : [];

            return [$dotted, ...$nested];
        })->all());
    }
}
