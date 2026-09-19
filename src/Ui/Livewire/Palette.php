<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
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
        $groups = [];
        $count = 0;
        foreach ($ui->query->search($this->term, self::LIMIT) as $hit) {
            $group = SectionPresentation::for($hit->section)->label;
            $groups[$group][] = [
                'type' => NodeType::forSection($hit->section) ?? NodeType::EVENT,
                'label' => $hit->label,
                'subtitle' => $hit->subtitle,
                'url' => $ui->links->hit($hit),
            ];
            $count++;
        }

        return app('view')->make('loom::livewire.palette', ['term' => trim($this->term), 'groups' => $groups, 'count' => $count]);
    }
}
