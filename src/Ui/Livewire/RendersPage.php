<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Livewire;

use Illuminate\Contracts\View\View;

/**
 * Renders a full-page component view with data for the shared layout
 * (`title`, `crumbs`, `active`).
 */
trait RendersPage
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $layout
     */
    protected function renderPage(string $view, array $data, array $layout): View
    {
        $rendered = app('view')->make($view, $data);

        // layoutData() is a macro Livewire registers on the view.
        // @phpstan-ignore method.notFound
        return $rendered->layoutData($layout);
    }
}
