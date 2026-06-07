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
use Lucasp\Loom\Dto\RouteEntry;
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
            $entry instanceof RouteEntry => $this->route($entry),
            $entry instanceof UnresolvedDispatchEntry => $this->unresolvedDispatch($entry),
            $entry instanceof DispatchSiteRecord => $this->dispatchSiteRecord($entry),
            default => throw new RuntimeException('IndexSerializer cannot serialize '.$entry::class),
        };
    }

    /** @return array<string, mixed> */
    public function dispatchSiteRecord(DispatchSiteRecord $e): array
    {
        // `classFqcn`, `form`, `provisionalKind`, `inClosure` are internal-only
        // routing tags (not schema properties); they live on `_dispatch_sites`
        // and are stripped before schema validation.
        $out = [
            'classFqcn' => $e->classFqcn,
            Field::METHOD->value => $e->method,
            Field::TARGET->value => $e->target,
            'form' => $e->form->value,
            'provisionalKind' => $e->provisionalKind->value,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::CONFIDENCE->value => $e->confidence,
        ];

        // Internal-only: carried on `_dispatch_sites` (stripped before schema
        // validation) so DispatchedFromPhase can surface it. Omitted entirely
        // when empty to keep existing site arrays byte-identical.
        if (! $e->overrides->isEmpty()) {
            $out[Field::OVERRIDES->value] = $e->overrides->toArray();
        }

        // Internal-only: the static channel filter from Notification::send/
        // sendNow arg 2, carried on `_dispatch_sites` (stripped before schema
        // validation). Omitted when absent so existing site arrays stay
        // byte-identical.
        if ($e->channels !== null && $e->channels !== []) {
            $out[Field::CHANNELS->value] = $e->channels;
        }

        // Internal-only tag: lets cross-link phases distinguish closure-internal
        // sites (consumed solely by ClosureDispatchAttributionPhase) from all
        // others. Carried on `_dispatch_sites`, stripped before schema validation.
        $out['inClosure'] = $e->inClosure;

        return $out;
    }

    /** @return array<string, mixed> */
    public function event(EventEntry $e): array
    {
        return [
            Field::ID->value => $e->id,
            Field::FQCN->value => $e->fqcn,
            Field::KIND->value => 'class',
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::DISPATCHED_FROM->value => [],
            Field::HANDLED_BY->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function modelEvent(ModelEventEntry $e): array
    {
        return [
            Field::ID->value => $e->id,
            Field::KIND->value => 'model_event',
            Field::MODEL->value => $e->model,
            Field::EVENT->value => $e->event,
            Field::HANDLED_BY->value => $e->handledBy,
        ];
    }

    /** @return array<string, mixed> */
    public function listener(ListenerEntry $e): array
    {
        return [
            Field::FQCN->value => $e->fqcn,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::HANDLES->value => array_map(fn (ListenerHandle $h): array => [
                Field::EVENT->value => $h->event,
                Field::METHOD->value => $h->method,
            ], $e->handles),
            Field::REGISTRATION->value => $e->registration->value,
            Field::QUEUED->value => $e->queued,
            Field::DISPATCHES->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function observer(ObserverEntry $e): array
    {
        return [
            Field::FQCN->value => $e->fqcn,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::OBSERVES->value => $e->observes,
            Field::REGISTRATION->value => $e->registration->value,
            Field::HOOKS->value => $e->hooks,
            Field::DISPATCHES->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function job(JobEntry $e): array
    {
        return [
            Field::FQCN->value => $e->fqcn,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::QUEUED->value => $e->queued,
            Field::QUEUE_CONFIG->value => $this->queueConfig($e->queueConfig),
            Field::DISPATCHED_FROM->value => [],
            Field::DISPATCHES->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function mailable(MailableEntry $e): array
    {
        return [
            Field::FQCN->value => $e->fqcn,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::QUEUED->value => $e->queued,
            Field::QUEUE_CONFIG->value => $this->queueConfig($e->queueConfig),
            Field::SENT_FROM->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function notification(NotificationEntry $e): array
    {
        return [
            Field::FQCN->value => $e->fqcn,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::QUEUED->value => $e->queued,
            Field::QUEUE_CONFIG->value => $this->queueConfig($e->queueConfig),
            Field::NOTIFIED_FROM->value => [],
            Field::CHANNELS->value => $e->channels,
            Field::CHANNELS_DYNAMIC->value => $e->channelsDynamic,
        ];
    }

    /** @return array<string, mixed> */
    public function closureListener(ClosureListenerEntry $e): array
    {
        return [
            Field::EVENT->value => $e->event,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::END_LINE->value => $e->endLine,
            Field::REGISTRATION->value => $e->registration->value,
            Field::QUEUED->value => $e->queued,
            Field::DISPATCHES->value => [],
        ];
    }

    /** @return array<string, mixed> */
    public function scheduled(ScheduledEntry $e): array
    {
        return [
            Field::KIND->value => $e->kind->value,
            Field::NAME->value => $e->name,
            Field::TARGET->value => $e->target,
            Field::CRON->value => $e->cron,
            Field::TIMEZONE->value => $e->timezone,
            Field::WITHOUT_OVERLAPPING->value => $e->withoutOverlapping,
            Field::ON_ONE_SERVER->value => $e->onOneServer,
            Field::RUN_IN_BACKGROUND->value => $e->runInBackground,
            Field::EVEN_IN_MAINTENANCE_MODE->value => $e->evenInMaintenanceMode,
            Field::CONSTRAINTS->value => $e->constraints,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
        ];
    }

    /** @return array<string, mixed> */
    public function route(RouteEntry $e): array
    {
        return [
            Field::METHOD->value => $e->method,
            Field::URI->value => $e->uri,
            Field::NAME->value => $e->name,
            Field::CONTROLLER_FQCN->value => $e->controllerFqcn,
            Field::CONTROLLER_METHOD->value => $e->controllerMethod,
            Field::MIDDLEWARE->value => $e->middleware,
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::DISPATCHES->value => $e->dispatches,
        ];
    }

    /** @return array<string, mixed> */
    public function unresolvedDispatch(UnresolvedDispatchEntry $e): array
    {
        return [
            Field::FILE->value => $e->file,
            Field::LINE->value => $e->line,
            Field::EXPRESSION->value => $e->expression,
            Field::REASON->value => $e->reason,
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
            Field::CONNECTION->value => $q->connection,
            Field::QUEUE->value => $q->queue,
            Field::DELAY->value => $q->delay,
            Field::TRIES->value => $q->tries,
            Field::TIMEOUT->value => $q->timeout,
            Field::BACKOFF->value => $q->backoff,
        ];
    }
}
