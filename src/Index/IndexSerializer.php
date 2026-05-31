<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Dto\ClosureListenerEntry;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\ListenerEntry;
use Lucasp\Loom\Dto\ListenerHandle;
use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Dto\ModelEventEntry;
use Lucasp\Loom\Dto\NotificationEntry;
use Lucasp\Loom\Dto\ObserverEntry;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Dto\ScheduledEntry;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use RuntimeException;

/**
 * Converts section-output DTOs into the schema-shaped associative arrays the
 * cross-link pass and the JSON encoder consume.
 *
 * The cross-link pass mutates `dispatched_from[]`, `dispatches[]`, etc., so
 * those keys are initialized to `[]` here; downstream code appends.
 */
final class IndexSerializer
{
    /**
     * Serialize a section's entries. Already-serialized arrays pass through
     * untouched so scanner-by-scanner migration stays incremental.
     *
     * @param  list<object|array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function section(string $section, array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[] = is_object($entry) ? $this->serialize($entry) : $entry;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function serialize(object $entry): array
    {
        return match (true) {
            $entry instanceof EventEntry => $this->event($entry),
            $entry instanceof ModelEventEntry => $this->modelEvent($entry),
            $entry instanceof ListenerEntry => $this->listener($entry),
            $entry instanceof ObserverEntry => $this->observer($entry),
            $entry instanceof JobEntry => $this->job($entry),
            $entry instanceof MailableEntry => $this->mailable($entry),
            $entry instanceof NotificationEntry => $this->notification($entry),
            $entry instanceof ClosureListenerEntry => $this->closureListener($entry),
            $entry instanceof ScheduledEntry => $this->scheduled($entry),
            $entry instanceof UnresolvedDispatchEntry => $this->unresolvedDispatch($entry),
            $entry instanceof DispatchSiteRecord => $this->dispatchSiteRecord($entry),
            default => throw new RuntimeException('IndexSerializer cannot serialize '.$entry::class),
        };
    }

    /** @return array<string, mixed> */
    public function dispatchSiteRecord(DispatchSiteRecord $e): array
    {
        $out = [
            'classFqcn' => $e->classFqcn,
            'method' => $e->method,
            'target' => $e->target,
            'form' => $e->form->value,
            'provisionalKind' => $e->provisionalKind->value,
            'file' => $e->file,
            'line' => $e->line,
            'confidence' => $e->confidence,
        ];

        // Internal-only: carried on `_dispatch_sites` (stripped before schema
        // validation) so DispatchedFromPhase can surface it. Omitted entirely
        // when empty to keep existing site arrays byte-identical.
        if (! $e->overrides->isEmpty()) {
            $out['overrides'] = $e->overrides->toArray();
        }

        // Internal-only: the static channel filter from Notification::send/
        // sendNow arg 2, carried on `_dispatch_sites` (stripped before schema
        // validation). Omitted when absent so existing site arrays stay
        // byte-identical.
        if ($e->channels !== null && $e->channels !== []) {
            $out['channels'] = $e->channels;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function event(EventEntry $e): array
    {
        return [
            'id' => $e->id,
            'fqcn' => $e->fqcn,
            'kind' => 'class',
            'file' => $e->file,
            'line' => $e->line,
            'dispatched_from' => [],
            'handled_by' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function modelEvent(ModelEventEntry $e): array
    {
        return [
            'id' => $e->id,
            'kind' => 'model_event',
            'model' => $e->model,
            'event' => $e->event,
            'handled_by' => $e->handledBy,
        ];
    }

    /** @return array<string, mixed> */
    public function listener(ListenerEntry $e): array
    {
        return [
            'fqcn' => $e->fqcn,
            'file' => $e->file,
            'line' => $e->line,
            'handles' => array_map(fn (ListenerHandle $h): array => [
                'event' => $h->event,
                'method' => $h->method,
            ], $e->handles),
            'registration' => $e->registration->value,
            'queued' => $e->queued,
            'dispatches' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function observer(ObserverEntry $e): array
    {
        return [
            'fqcn' => $e->fqcn,
            'file' => $e->file,
            'line' => $e->line,
            'observes' => $e->observes,
            'registration' => $e->registration->value,
            'hooks' => $e->hooks,
            'dispatches' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function job(JobEntry $e): array
    {
        return [
            'fqcn' => $e->fqcn,
            'file' => $e->file,
            'line' => $e->line,
            'queued' => $e->queued,
            'queue_config' => $this->queueConfig($e->queueConfig),
            'dispatched_from' => [],
            'dispatches' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function mailable(MailableEntry $e): array
    {
        return [
            'fqcn' => $e->fqcn,
            'file' => $e->file,
            'line' => $e->line,
            'queued' => $e->queued,
            'queue_config' => $this->queueConfig($e->queueConfig),
            'sent_from' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function notification(NotificationEntry $e): array
    {
        return [
            'fqcn' => $e->fqcn,
            'file' => $e->file,
            'line' => $e->line,
            'queued' => $e->queued,
            'queue_config' => $this->queueConfig($e->queueConfig),
            'notified_from' => [],
            'channels' => $e->channels,
            'channels_dynamic' => $e->channelsDynamic,
        ];
    }

    /** @return array<string, mixed> */
    public function closureListener(ClosureListenerEntry $e): array
    {
        return [
            'event' => $e->event,
            'file' => $e->file,
            'line' => $e->line,
            'registration' => $e->registration->value,
            'queued' => $e->queued,
            'dispatches' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function scheduled(ScheduledEntry $e): array
    {
        return [
            'kind' => $e->kind->value,
            'target' => $e->target,
            'cron' => $e->cron,
            'timezone' => $e->timezone,
            'without_overlapping' => $e->withoutOverlapping,
            'on_one_server' => $e->onOneServer,
            'run_in_background' => $e->runInBackground,
            'constraints' => $e->constraints,
            'file' => $e->file,
            'line' => $e->line,
        ];
    }

    /** @return array<string, mixed> */
    public function unresolvedDispatch(UnresolvedDispatchEntry $e): array
    {
        return [
            'file' => $e->file,
            'line' => $e->line,
            'expression' => $e->expression,
            'reason' => $e->reason,
        ];
    }

    /**
     * @return array<string, string|int|null>|null
     */
    private function queueConfig(?QueueConfigData $q): ?array
    {
        if ($q === null) {
            return null;
        }

        return [
            'connection' => $q->connection,
            'queue' => $q->queue,
            'delay' => $q->delay,
            'tries' => $q->tries,
            'timeout' => $q->timeout,
            'backoff' => $q->backoff,
        ];
    }
}
