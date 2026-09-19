<?php

declare(strict_types=1);

arch('the query layer stays transport-agnostic')
    ->expect('Lucasp\Loom\Query')
    ->not->toUse(['Laravel\Mcp', 'Livewire', 'Illuminate\Http\Request', 'Illuminate\Http\Response', 'Lucasp\Loom\Mcp']);

arch('MCP tools do not touch the raw index or repository for computation')
    ->expect('Lucasp\Loom\Mcp\Tools')
    ->not->toUse('Lucasp\Loom\Index\Index');
