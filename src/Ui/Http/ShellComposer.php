<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http;

use Illuminate\Contracts\View\View;
use Lucasp\Loom\Ui\Dto\NavItem;
use Lucasp\Loom\Ui\SectionPresentation;
use Lucasp\Loom\Ui\Support\StaleIndex;
use Lucasp\Loom\Ui\UiContext;

/**
 * Feeds the shared layout: sidebar sections with counts, index meta, stale notice.
 *
 * @internal
 */
final class ShellComposer
{
    public function __construct(
        private readonly UiContext $context,
        private readonly StaleIndex $stale,
    ) {
    }

    public function compose(View $view): void
    {
        $query = $this->context->query;
        $meta = $query->meta();

        $nav = [];
        $go = ['d' => $this->context->links->dashboard()];
        foreach ($query->sections() as $info) {
            if ($info->present && $info->listed) {
                $spec = SectionPresentation::for($info->section);
                $url = $this->context->links->section($info->section);
                $nav[] = new NavItem($info->section, $spec->label, $info->count, $url);
                if ($spec->shortcut !== null) {
                    $go[$spec->shortcut] = $url;
                }
            }
        }

        $view->with([
            'nav' => $nav,
            'go' => $go,
            'meta' => $meta,
            'staleBy' => $this->stale->olderBy($meta->scannedAt),
            'links' => $this->context->links,
        ]);
    }
}
