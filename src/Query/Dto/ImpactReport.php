<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Closure;
use Lucasp\Loom\Index\Model\DispatchSite;
use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\ImpactEntity;
use Lucasp\Loom\Query\ImpactNote;

/**
 * Blast radius of removing or renaming a class. Which fields are populated
 * depends on `entity`: events carry dispatchers/handlers/downstream, listeners
 * and jobs carry handles/wouldOrphanEvents/dispatches.
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
        public ChangeKind $kind,
        public ImpactEntity $entity,
        public array $dispatchers = [],
        public array $handlers = [],
        public ?EventChain $downstream = null,
        public array $handles = [],
        public array $wouldOrphanEvents = [],
        public array $dispatches = [],
        public array $notes = [],
    ) {
    }

    /**
     * @param  (Closure(ImpactNote, self): string)|null  $renderNote  turns a note code into prose; codes are emitted when null
     * @return array<string, mixed>
     */
    public function toArray(?Closure $renderNote = null): array
    {
        $notes = array_map(
            fn (ImpactNote $note): string => $renderNote === null ? $note->value : $renderNote($note, $this),
            $this->notes,
        );

        $head = ['fqcn' => $this->fqcn, 'kind' => $this->kind->value, 'entity' => $this->entity->value];

        if ($this->entity === ImpactEntity::EVENT || $this->entity === ImpactEntity::UNKNOWN) {
            return $head + [
                'dispatchers' => array_map(static fn (DispatchSite $s): array => [
                    'file' => $s->file,
                    'line' => $s->line,
                    'method' => $s->method,
                ], $this->dispatchers),
                'handlers' => array_map(static fn (HandlerRef $h): array => $h->toArray(), $this->handlers),
                'downstream' => $this->downstream?->toArray(),
                'notes' => $notes,
            ];
        }

        return $head + [
            'handles' => $this->handles,
            'would_orphan_events' => $this->wouldOrphanEvents,
            'dispatches' => array_map(static fn (DispatchRef $d): array => $d->toArray(), $this->dispatches),
            'notes' => $notes,
        ];
    }
}
