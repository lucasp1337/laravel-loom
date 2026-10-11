<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Illuminate\Support\Str;
use Lucasp\Loom\Tools\Regions\Region;

/** A table of config keys on the reference pages and the keys it lists. */
enum ConfigGroup
{
    case Scan;
    case Index;
    case Ui;
    case Mcp;

    /** The region that holds this group's table. */
    public function region(): Region
    {
        return match ($this) {
            self::Scan => Region::ConfigScan,
            self::Index => Region::ConfigIndex,
            self::Ui => Region::ConfigUi,
            self::Mcp => Region::ConfigMcp,
        };
    }

    /**
     * Dotted keys the group lists; a trailing dot takes a whole config group.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Scan => ['scan.'],
            self::Index => ['index_path'],
            self::Ui => ['ui.'],
            self::Mcp => ['mcp.'],
        };
    }

    public function includes(string $key): bool
    {
        foreach ($this->prefixes() as $prefix) {
            // A trailing dot names a whole group; anything else is one exact key.
            $hit = Str::endsWith($prefix, '.') ? Str::startsWith($key, $prefix) : $key === $prefix;
            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
