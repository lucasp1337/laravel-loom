<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

/**
 * Gate abilities the UI checks.
 */
enum LoomAbility: string
{
    case VIEW = 'viewLoom';
}
