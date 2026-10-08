<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Laravel\Mcp\Server\Registrar;
use Livewire\Livewire;

/**
 * Composer packages Loom works without. Each unlocks one surface.
 *
 * @internal
 */
enum OptionalPackage: string
{
    case LIVEWIRE = 'livewire/livewire';
    case MCP = 'laravel/mcp';

    /** A class that exists only when the package is installed. */
    public function probeClass(): string
    {
        return match ($this) {
            self::LIVEWIRE => Livewire::class,
            self::MCP => Registrar::class,
        };
    }

    public function surface(): string
    {
        return match ($this) {
            self::LIVEWIRE => 'the browser UI',
            self::MCP => 'the MCP server',
        };
    }

    public function constraint(): string
    {
        return match ($this) {
            self::LIVEWIRE => '^3.8|^4.0',
            self::MCP => '^1.0',
        };
    }

    public function installHint(): string
    {
        return "Install {$this->value} to enable {$this->surface()}: composer require --dev {$this->value}";
    }
}
