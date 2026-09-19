<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Ui\NodeType;
use Lucasp\Loom\Ui\Support\Fqcn;
use Lucasp\Loom\Ui\UiContext;

#[Layout('loom::layouts.app')]
class EventDetail extends Component
{
    use RendersPage;

    #[Locked]
    public string $fqcn = '';

    public function mount(string $fqcn): void
    {
        $this->fqcn = Fqcn::fromSlug($fqcn);
    }

    public function render(UiContext $ui): View
    {
        $query = $ui->query;
        $event = $query->entity(EntityKind::EVENT, $this->fqcn);

        if ($event === null) {
            abort(response()->view('loom::errors.not-found', ['fqcn' => $this->fqcn], 404));
        }

        $handlers = $query->handlersFor($this->fqcn);
        $sites = $query->dispatchSitesFor($this->fqcn);

        // One hop only: what this event's handlers dispatch next.
        $downstream = [];
        foreach ($query->eventChain($this->fqcn, 1)->edges as $edge) {
            foreach ($edge->dispatches as $dispatch) {
                $downstream[] = [
                    'type' => NodeType::forDispatch($dispatch->kind),
                    'target' => $dispatch->target,
                    'url' => $ui->links->forFqcn($dispatch->target),
                    'via' => $edge->handler,
                    'location' => $dispatch->file.':'.$dispatch->line,
                    'isEvent' => $dispatch->kind === DispatchKinds::EVENT,
                ];
            }
        }

        return $this->renderPage('loom::livewire.event-detail', [
            'event' => $event,
            'short' => Fqcn::short($this->fqcn),
            'namespace' => Fqcn::namespace($this->fqcn),
            'handlers' => $handlers,
            'sites' => $sites,
            'downstream' => $downstream,
            'links' => $ui->links,
        ], [
            'title' => Fqcn::short($this->fqcn),
            'crumbs' => [
                ['loom', $ui->links->dashboard()],
                ['events', $ui->links->section(Sections::EVENTS)],
                [Fqcn::short($this->fqcn), null],
            ],
            'active' => Sections::EVENTS,
        ]);
    }
}
