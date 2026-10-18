<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Regions;

/** Regions with a fixed name. Open-ended families use {@see RegionPrefix}. */
enum Region: string
{
    case SchemaTop = 'schema:top';
    case McpOverview = 'mcp:overview';
    case ConfigScan = 'config:scan';
    case ConfigIndex = 'config:index';
    case ConfigUi = 'config:ui';
    case ConfigMcp = 'config:mcp';
    case Examples = 'examples';
    case PhpApiQuickstart = 'php-api:quickstart';
    case PhpApiClasses = 'php-api:classes';
}
