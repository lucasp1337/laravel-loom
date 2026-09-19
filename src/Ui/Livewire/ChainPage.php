<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Ui\LoomConfig;
use Lucasp\Loom\Ui\NodeType;
use Lucasp\Loom\Ui\Support\ChainGraph;
use Lucasp\Loom\Ui\Support\Fqcn;
use Lucasp\Loom\Ui\Support\NodeFacts;
use Lucasp\Loom\Ui\UiContext;

/** Livewire owns depth, selection and collapsed nodes; Alpine `loomChain` owns the canvas. */
#[Layout('loom::layouts.app')]
class ChainPage extends Component
{
    use RendersPage;

    private const MAX_COLLAPSED = 200;

    #[Locked]
    public string $root = '';

    #[Url(except: '')]
    public int|string|null $depth = null;

    #[Url(except: '')]
    public ?string $node = null;

    /** @var list<string> */
    public array $collapsed = [];

    public function mount(string $fqcn): void
    {
        $this->root = Fqcn::fromSlug($fqcn);
        $this->node ??= $this->root;
    }

    public function setDepth(int $depth): void
    {
        $this->depth = LoomConfig::clampDepth($depth);
        $this->collapsed = [];
    }

    public function select(?string $key): void
    {
        $this->node = $key;
    }

    public function toggle(string $key): void
    {
        $this->collapsed = in_array($key, $this->collapsed, true)
            ? array_values(array_diff($this->collapsed, [$key]))
            : array_slice([...$this->collapsed, $key], -self::MAX_COLLAPSED);
    }

    public function render(UiContext $ui, LoomConfig $config): View
    {
        if ($ui->query->entity(EntityKind::EVENT, $this->root) === null) {
            abort(response()->view('loom::errors.not-found', ['fqcn' => $this->root], 404));
        }

        $depth = is_numeric($this->depth) ? LoomConfig::clampDepth((int) $this->depth) : $config->chainDepth();
        $graph = ChainGraph::build($ui->query->eventChain($this->root, $depth), array_slice(array_values(array_filter($this->collapsed, is_string(...))), 0, self::MAX_COLLAPSED), $this->node);

        $panel = null;
        $found = false;
        foreach ($graph['nodes'] as $node) {
            if ($node['key'] !== $this->node) {
                continue;
            }
            $found = true;
            if ($node['type'] !== NodeType::CYCLE->value) {
                $type = NodeType::from(is_string($node['type']) ? $node['type'] : '');
                $panel = NodeFacts::for($ui->query, $ui->links, $type, is_string($node['id']) ? $node['id'] : '');
            }
            break;
        }

        if (! $found) {
            $this->node = null;
        }

        return $this->renderPage('loom::livewire.chain-page', [
            'graph' => $graph,
            'depth' => $depth,
            'depths' => range(ChainDepth::MIN, ChainDepth::MAX),
            'panel' => $panel,
            'short' => Fqcn::short($this->root),
            'hasHandlers' => count($graph['nodes']) > 1,
            'links' => $ui->links,
            'types' => [NodeType::EVENT, NodeType::LISTENER, NodeType::CLOSURE, NodeType::JOB, NodeType::MAILABLE, NodeType::NOTIFICATION, NodeType::CYCLE],
        ], [
            'title' => 'Chain · '.Fqcn::short($this->root),
            'crumbs' => [
                ['loom', $ui->links->dashboard()],
                ['events', $ui->links->section(Sections::EVENTS)],
                [Fqcn::short($this->root), $ui->links->entity(EntityKind::EVENT, $this->root)],
                ['chain', null],
            ],
            'active' => Sections::EVENTS,
        ]);
    }
}
