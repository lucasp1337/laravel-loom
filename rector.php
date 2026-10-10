<?php

declare(strict_types=1);

use Lucasp\Loom\Rector\IfElseifChainToMatchRector;
use Lucasp\Loom\Rector\NativeFunctionToLaravelHelperRector;
use Rector\CodeQuality\Rector\Switch_\SwitchTrueToMatchRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;

/**
 * Project Rector config: only mechanical, output-preserving rewrites that the
 * house style asks for (see CONTRIBUTING.md). `composer rector` is a dry run
 * that fails on a diff; `composer rector:fix` applies it. Rewritten calls use
 * fully qualified class names; run Pint afterwards to import them.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withRules([
        // Project rules (tools/rector).
        IfElseifChainToMatchRector::class,
        NativeFunctionToLaravelHelperRector::class,

        // Core rules: a switch of plain returns/assigns, and `switch (true)` of strict comparisons.
        ChangeSwitchToMatchRector::class,
        SwitchTrueToMatchRector::class,
    ])
    ->withSkip([
        // Mirrors NATIVE_FUNCTION_ALLOWLIST in tests/Unit/Support/NativeFunctionsTest.php:
        // hot per-node paths keep the native calls on purpose.
        NativeFunctionToLaravelHelperRector::class => [
            __DIR__.'/src/Scanners/Visitors',
            __DIR__.'/src/Support/Ast/EventsDispatcher.php',
            __DIR__.'/src/Support/ClassHierarchyResolver.php',
            __DIR__.'/src/Console/ShowCommand.php',
            __DIR__.'/src/Index/Index.php',
            __DIR__.'/src/Ui/Livewire/ChainPage.php',
        ],
    ]);
