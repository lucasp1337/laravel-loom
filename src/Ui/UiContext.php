<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Query\IndexSource;
use Lucasp\Loom\Ui\Support\Links;

/**
 * The UI's view of the index: a snapshot-backed source and the query layer over it.
 */
final readonly class UiContext
{
    public IndexQuery $query;

    public Links $links;

    public function __construct(public IndexSource $source)
    {
        $this->query = new IndexQuery($source);
        $this->links = new Links($this->query);
    }
}
