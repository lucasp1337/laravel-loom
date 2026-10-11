<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools;

use Lucasp\Loom\Tools\Pages\DocsFixture;

/** What a region source may read: the repository root and the docs fixture app. */
final readonly class SourceContext
{
    public DocsFixture $fixture;

    public function __construct(public string $root)
    {
        $this->fixture = new DocsFixture($root);
    }
}
