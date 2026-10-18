<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Regions;

/** Families of regions named `prefix:key`, one per schema section, shape, tool group, command or example primitive. */
enum RegionPrefix: string
{
    case SchemaSection = 'schema';
    case SchemaShape = 'shape';
    case McpGroup = 'mcp';
    case Command = 'command';
    case Examples = 'examples';
}
