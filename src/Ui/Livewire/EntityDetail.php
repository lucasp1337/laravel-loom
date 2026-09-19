<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\Support\EntityFacts;
use Lucasp\Loom\Ui\Support\Fqcn;
use Lucasp\Loom\Ui\UiContext;

/**
 * Generic detail page for any entity kind; events use {@see EventDetail}.
 */
#[Layout('loom::layouts.app')]
class EntityDetail extends Component
{
    use RendersPage;

    #[Locked]
    public string $section = '';

    #[Locked]
    public string $fqcn = '';

    public function mount(string $section, string $fqcn): void
    {
        $this->section = Sections::from($section)->value;
        $this->fqcn = Fqcn::fromSlug($fqcn);
    }

    public function render(UiContext $ui): View
    {
        $section = Sections::from($this->section);
        $kind = EntityKind::forSection($section) ?? abort(404);
        $entity = $ui->query->entity($kind, $this->fqcn);

        if ($entity === null) {
            abort(response()->view('loom::errors.not-found', ['fqcn' => $this->fqcn], 404));
        }

        $spec = SectionPresentation::for($section);

        return $this->renderPage('loom::livewire.entity-detail', [
            'spec' => $spec,
            'fqcn' => $this->fqcn,
            'short' => Fqcn::short($this->fqcn),
            'namespace' => Fqcn::namespace($this->fqcn),
            'rows' => (new EntityFacts($ui->links))->rows($entity),
        ], [
            'title' => Fqcn::short($this->fqcn),
            'crumbs' => [
                ['loom', $ui->links->dashboard()],
                [strtolower($spec->label), $ui->links->section($section)],
                [Fqcn::short($this->fqcn), null],
            ],
            'active' => $section,
        ]);
    }
}
