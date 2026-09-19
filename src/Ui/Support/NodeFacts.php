<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Job;
use Lucasp\Loom\Index\Model\Listener;
use Lucasp\Loom\Index\Model\Mailable;
use Lucasp\Loom\Index\Model\Notification;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Ui\NodeType;

/**
 * Side-panel content for one chain node: header names, fact rows, and the
 * page the "Go to page" button opens.
 */
final readonly class NodeFacts
{
    /**
     * @param  list<array{0: string, 1: string}>  $facts  label/value rows
     */
    public function __construct(
        public NodeType $type,
        public string $short,
        public string $namespace,
        public array $facts,
        public ?string $url,
    ) {
    }

    public static function for(IndexQuery $query, Links $links, NodeType $type, string $id): self
    {
        $short = Fqcn::short($id);
        $namespace = Fqcn::namespace($id);
        $facts = [];
        $url = null;

        $entity = match ($type) {
            NodeType::EVENT => $query->entity(EntityKind::EVENT, $id),
            NodeType::LISTENER => $query->entity(EntityKind::LISTENER, $id),
            NodeType::JOB => $query->entity(EntityKind::JOB, $id),
            NodeType::MAILABLE => $query->entity(EntityKind::MAILABLE, $id),
            NodeType::NOTIFICATION => $query->entity(EntityKind::NOTIFICATION, $id),
            default => null,
        };

        if ($type === NodeType::CLOSURE) {
            $short = $id;
            $namespace = '';
            $facts[] = ['File', $id];
            $url = $links->section(Sections::CLOSURE_LISTENERS, preg_replace('/:\d+$/', '', $id));
        }

        if (is_object($entity) && property_exists($entity, 'file') && property_exists($entity, 'line')) {
            $facts[] = ['File', self::str($entity->file).':'.self::str($entity->line)];
        }

        switch (true) {
            case $entity instanceof Event:
                $facts[] = ['Handlers', (string) count($entity->handledBy)];
                $facts[] = ['Sites', (string) count($entity->dispatchedFrom)];
                $url = $links->entity(EntityKind::EVENT, $id);
                break;
            case $entity instanceof Listener:
                $facts[] = ['Handles', $entity->handles === [] ? 'unresolved' : implode(', ', array_map(static fn ($h): string => Fqcn::short($h->event), $entity->handles))];
                $facts[] = ['Registration', $entity->registration->value];
                $facts[] = ['Queued', $entity->queued ? 'yes' : 'no'];
                $url = $links->entity(EntityKind::LISTENER, $id);
                break;
            case $entity instanceof Job:
                $facts[] = ['Queue', self::str($entity->queueConfig?->queue) ?: 'default'];
                $facts[] = ['Queued', $entity->queued ? 'yes' : 'no (sync)'];
                $facts[] = ['Sites', (string) count($entity->dispatchedFrom)];
                $url = $links->entity(EntityKind::JOB, $id);
                break;
            case $entity instanceof Mailable:
                $facts[] = ['Queued', $entity->queued ? 'yes' : 'no'];
                $url = $links->entity(EntityKind::MAILABLE, $id);
                break;
            case $entity instanceof Notification:
                $facts[] = ['Channels', $entity->channelsDynamic ? 'dynamic' : implode(', ', $entity->channels)];
                $url = $links->entity(EntityKind::NOTIFICATION, $id);
                break;
        }

        return new self($type, $short, $namespace, $facts, $url);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
