<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Lucasp\Loom\Index\SectionRegistry;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\UiContext;

#[Layout('loom::layouts.app')]
class Dashboard extends Component
{
    use RendersPage;

    private const LIST_LIMIT = 8;

    public function render(UiContext $ui): View
    {
        $dashboard = $ui->query->dashboard();
        $orphans = $ui->query->orphans();

        $stats = [];
        foreach (SectionRegistry::statsNames() as $name) {
            $section = Sections::from($name);
            $stats[] = [
                'label' => SectionPresentation::for($section)->label,
                'count' => $dashboard->counts[$name] ?? 0,
                'url' => $ui->links->section($section),
            ];
        }

        return $this->renderPage('loom::livewire.dashboard', [
            'dashboard' => $dashboard,
            'stats' => $stats,
            'orphanEvents' => array_slice($orphans->events, 0, self::LIST_LIMIT),
            'idleListeners' => array_slice($orphans->idleListeners, 0, self::LIST_LIMIT),
            'unresolved' => array_slice($ui->query->unresolvedDispatches(), 0, self::LIST_LIMIT),
            'links' => $ui->links,
        ], ['title' => 'Dashboard', 'crumbs' => [['Dashboard', null]], 'active' => null]);
    }
}
