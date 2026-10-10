<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Closure;
use Illuminate\Support\Arr;
use Lucasp\Loom\Index\Model\DispatchSite;
use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\ImpactEntity;
use Lucasp\Loom\Query\ImpactNote;

/**
 * Blast radius of removing or renaming a class. Which fields are populated
 * depends on `kind`: events carry dispatchers/handlers/downstream, listeners
 * and jobs carry handles/wouldOrphanEvents/dispatches.
 *
 * @internal
 */
final readonly class ImpactReport
{
    /**
     * @param  list<DispatchSite>  $dispatchers
     * @param  list<HandlerRef>  $handlers
     * @param  list<string>  $handles
     * @param  list<string>  $wouldOrphanEvents
     * @param  list<DispatchRef>  $dispatches
     * @param  list<ImpactNote>  $notes
     */
    public function __construct(
        public string $fqcn,
        public ChangeKind $change,
        public ImpactEntity $kind,
        public array $dispatchers = [],
        public array $handlers = [],
        public ?EventChain $downstream = null,
        public array $handles = [],
        public array $wouldOrphanEvents = [],
        public array $dispatches = [],
        public array $notes = [],
    ) {}

    /**
     * @param  (Closure(ImpactNote, self): string)|null  $renderNote  turns a note code into prose; codes are emitted when null
     * @return array<string, mixed>
     */
    public function toArray(?Closure $renderNote = null): array
    {
        $notes = Arr::map($this->notes, fn (ImpactNote $note): string => $renderNote === null ? $note->value : $renderNote($note, $this));

        $head = ['fqcn' => $this->fqcn, 'change' => $this->change->value, 'kind' => $this->kind->value];

        if ($this->kind === ImpactEntity::EVENT || $this->kind === ImpactEntity::UNKNOWN) {
            return $head + [
                'dispatchers' => Arr::map($this->dispatchers, static fn (DispatchSite $s): array => [
                    'file' => $s->file,
                    'line' => $s->line,
                    'method' => $s->method,
                ]),
                'handlers' => Arr::map($this->handlers, static fn (HandlerRef $h): array => $h->toArray()),
                'downstream' => $this->downstream?->toArray(),
                'notes' => $notes,
            ];
        }

        return $head + [
            'handles' => $this->handles,
            'would_orphan_events' => $this->wouldOrphanEvents,
            'dispatches' => Arr::map($this->dispatches, static fn (DispatchRef $d): array => $d->toArray()),
            'notes' => $notes,
        ];
    }
}
