<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\Dto\SectionQuery;
use Lucasp\Loom\Query\SortDirection;
use Lucasp\Loom\Query\SortField;
use Lucasp\Loom\Ui\ColumnRole;
use Lucasp\Loom\Ui\Dto\TableRow;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\SectionSpec;
use Lucasp\Loom\Ui\UiContext;

/**
 * One table component for every section, driven by {@see SectionPresentation}.
 */
#[Layout('loom::layouts.app')]
class SectionIndex extends Component
{
    use RendersPage;

    private const PER_PAGE = 50;

    #[Locked]
    public string $section = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: SortDirection::ASC->value)]
    public string $dir = SortDirection::ASC->value;

    #[Url(except: 1)]
    public int|string $page = 1;

    public function mount(string $section): void
    {
        $this->section = Sections::from($section)->value;
    }

    public function sortBy(string $field): void
    {
        $sort = SortField::tryFrom($field);
        if ($sort === null) {
            return;
        }

        $current = $this->activeSort(SectionPresentation::for(Sections::from($this->section)));

        $this->dir = $current === $sort && $this->dir === SortDirection::ASC->value
            ? SortDirection::DESC->value
            : SortDirection::ASC->value;
        $this->sort = $sort->value;
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function clearFilter(): void
    {
        $this->search = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    private function pageNumber(): int
    {
        return is_numeric($this->page) ? max(1, (int) $this->page) : 1;
    }

    public function render(UiContext $ui): View
    {
        $section = Sections::from($this->section);
        $spec = SectionPresentation::for($section);

        $sort = $this->activeSort($spec);
        $dir = SortDirection::tryFrom($this->dir) ?? SortDirection::ASC;
        $search = trim($this->search) === '' ? null : trim($this->search);

        $page = $ui->query->list($section, new SectionQuery($search, [], $sort, $dir, $this->pageNumber(), self::PER_PAGE));
        $total = $this->totalRows($ui, $section);

        $rows = [];
        foreach ($page->items as $item) {
            $cells = [];
            foreach ($spec->columns as $column) {
                $cells[] = $column->cell($item);
            }
            $rows[] = new TableRow($cells, $this->rowUrl($ui, $spec, $item), $spec->isOrphan($item));
        }

        return $this->renderPage('loom::livewire.section-index', [
            'spec' => $spec,
            'rows' => $rows,
            'result' => $page,
            'total' => $total,
            'sort' => $sort,
            'dir' => $dir,
            'filtered' => $search !== null,
            'roleNumber' => ColumnRole::NUMBER,
            'roleName' => ColumnRole::NAME,
            'roleFile' => ColumnRole::FILE,
        ], [
            'title' => $spec->label,
            'crumbs' => [['loom', $ui->links->dashboard()], [strtolower($spec->label), null]],
            'active' => $section,
        ]);
    }

    /** The requested sort when the section has such a column, else name ascending when it has one. */
    private function activeSort(SectionSpec $spec): ?SortField
    {
        $requested = SortField::tryFrom($this->sort);
        $sortable = array_filter(array_map(static fn ($c): ?SortField => $c->sort, $spec->columns));

        if ($requested !== null && in_array($requested, $sortable, true)) {
            return $requested;
        }

        return in_array(SortField::NAME, $sortable, true) ? SortField::NAME : null;
    }

    private function totalRows(UiContext $ui, Sections $section): int
    {
        foreach ($ui->query->sections() as $info) {
            if ($info->section === $section) {
                return $info->count;
            }
        }

        return 0;
    }

    private function rowUrl(UiContext $ui, SectionSpec $spec, object $item): ?string
    {
        if ($spec->detailKind === null || ! property_exists($item, 'fqcn') || ! is_string($item->fqcn)) {
            return null;
        }

        return $ui->links->entity($spec->detailKind, $item->fqcn);
    }
}
