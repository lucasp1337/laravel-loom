<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Model\Dispatch;

final readonly class DispatchRef
{
    public function __construct(
        public string $target,
        public DispatchKinds $kind,
        public Confidence $confidence,
        public string $file,
        public int $line,
    ) {
    }

    public static function fromDispatch(Dispatch $dispatch): self
    {
        return new self($dispatch->target, $dispatch->kind, $dispatch->confidence, $dispatch->file, $dispatch->line);
    }

    /** @return array{target: string, kind: string, confidence: string, file: string, line: int} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'kind' => $this->kind->value,
            'confidence' => $this->confidence->value,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
