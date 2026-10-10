<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\RouteFileLoader;
use Lucasp\Loom\Support\RoutePathProblem;

/**
 * A route-file path Loom saw but could not follow.
 *
 * @internal
 */
final readonly class UnresolvedRoutePath
{
    public function __construct(
        public string $file,
        public int $line,
        public RouteFileLoader $loader,
        public RoutePathProblem $problem,
    ) {}

    public function message(): string
    {
        return $this->loader->value.': '.$this->problem->message();
    }
}
