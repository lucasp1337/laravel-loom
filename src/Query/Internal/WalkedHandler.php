<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Internal;

use Lucasp\Loom\Query\Dto\DispatchRef;
use Lucasp\Loom\Query\HandlerKind;

final readonly class WalkedHandler
{
    /** @param  list<DispatchRef>  $dispatches */
    public function __construct(
        public string $ref,
        public HandlerKind $kind,
        public ?string $file,
        public ?int $line,
        public array $dispatches,
    ) {
    }
}
