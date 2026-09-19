<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lucasp\Loom\Ui\Dto\PaletteGroup;
use Lucasp\Loom\Ui\Dto\PaletteItem;
use Lucasp\Loom\Ui\NodeType;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\UiContext;

/**
 * Global search behind the command palette.
 */
class Palette extends Component
{
    private const LIMIT = 20;

    public string $term = '';

    public function render(UiContext $ui): View
    {
        $byGroup = [];
        $count = 0;
        foreach ($ui->query->search($this->term, self::LIMIT) as $hit) {
            $byGroup[SectionPresentation::for($hit->section)->label][] = new PaletteItem(
                NodeType::forSection($hit->section) ?? NodeType::EVENT,
                $hit->label,
                $hit->subtitle,
                $ui->links->hit($hit),
            );
            $count++;
        }

        $groups = [];
        foreach ($byGroup as $label => $items) {
            $groups[] = new PaletteGroup((string) $label, $items);
        }

        return app('view')->make('loom::livewire.palette', ['term' => trim($this->term), 'groups' => $groups, 'count' => $count]);
    }
}
