<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

/**
 * Gate abilities the UI checks.
 *
 * @internal
 */
enum LoomAbility: string
{
    case VIEW = 'viewLoom';
}
