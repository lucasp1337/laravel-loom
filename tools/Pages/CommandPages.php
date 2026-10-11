<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Console\Command;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\RegionName;
use Lucasp\Loom\Tools\Regions\RegionPrefix;
use Lucasp\Loom\Tools\SourceContext;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * One argument and option table per Artisan command, built from the command's
 * signature, so the documented flags and their meanings cannot drift.
 */
final class CommandPages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        $regions = [];
        foreach ($this->commands() as $command) {
            $table = $this->table($command);
            if ($table === null) {
                continue;
            }
            $regions[] = new GeneratedRegion(
                RegionName::keyed(RegionPrefix::Command, (string) $command->getName()),
                $table,
            );
        }

        return $regions;
    }

    /** @return list<Command> */
    private function commands(): array
    {
        $commands = [];
        foreach (glob($this->context->root.'/src/Console/*Command.php') ?: [] as $file) {
            $class = 'Lucasp\\Loom\\Console\\'.basename($file, '.php');
            $command = new $class;
            if (! $command instanceof Command) {
                throw new RuntimeException("{$class} is not an Artisan command");
            }
            $commands[] = $command;
        }

        return $commands;
    }

    private function table(Command $command): ?string
    {
        $definition = $command->getDefinition();
        $arguments = array_values($definition->getArguments());
        $options = array_values($definition->getOptions());
        if ($arguments === [] && $options === []) {
            return null;
        }

        $rows = [];
        foreach ($arguments as $argument) {
            $rows[] = ['`'.$argument->getName().'`', Markdown::cell(Markdown::codeSpans($this->meaning($argument)))];
        }
        foreach ($options as $option) {
            $rows[] = ['`'.$this->optionLabel($option).'`', Markdown::cell(Markdown::codeSpans($this->meaning($option)))];
        }

        $header = match (true) {
            $arguments !== [] && $options !== [] => 'Argument or option',
            $arguments !== [] => 'Argument',
            default => 'Option',
        };

        return Markdown::table([$header, 'Meaning'], $rows);
    }

    private function optionLabel(InputOption $option): string
    {
        // A flag takes no value; every other option is written with `=`.
        return $option->acceptValue() ? '--'.$option->getName().'=' : '--'.$option->getName();
    }

    private function meaning(InputArgument|InputOption $input): string
    {
        $description = rtrim($input->getDescription(), '.');
        $repeatable = $input->isArray();

        return $repeatable ? $description.'. Repeatable.' : $description.'.';
    }
}
