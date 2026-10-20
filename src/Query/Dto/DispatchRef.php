<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Model\Dispatch;

/** @internal */
final readonly class DispatchRef
{
    public function __construct(
        public string $target,
        public DispatchKinds $kind,
        public Confidence $confidence,
        public string $file,
        public int $line,
        /** @var list<string> */
        public array $via = [],
    ) {}

    public static function fromDispatch(Dispatch $dispatch): self
    {
        return new self($dispatch->target, $dispatch->kind, $dispatch->confidence, $dispatch->file, $dispatch->line, $dispatch->via);
    }

    /**
     * Distinct targets of one kind, in first-seen order.
     *
     * @param  iterable<self>  $refs
     * @return list<string>
     */
    public static function targetsOf(iterable $refs, DispatchKinds $kind): array
    {
        return array_values(collect($refs)
            ->filter(static fn (self $ref): bool => $ref->kind === $kind)
            ->map(static fn (self $ref): string => $ref->target)
            ->unique()
            ->all());
    }

    /** @return array{target: string, kind: string, confidence: string, file: string, line: int, via?: list<string>} */
    public function toArray(): array
    {
        $out = [
            'target' => $this->target,
            'kind' => $this->kind->value,
            'confidence' => $this->confidence->value,
            'file' => $this->file,
            'line' => $this->line,
        ];

        // Only helper-reached dispatches carry the marker
        if ($this->via !== []) {
            $out['via'] = $this->via;
        }

        return $out;
    }
}
