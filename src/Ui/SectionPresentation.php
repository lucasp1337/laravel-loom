<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Lucasp\Loom\Index\Model\ClosureListener;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Job;
use Lucasp\Loom\Index\Model\Listener;
use Lucasp\Loom\Index\Model\Mailable;
use Lucasp\Loom\Index\Model\ModelEvent;
use Lucasp\Loom\Index\Model\Notification;
use Lucasp\Loom\Index\Model\Observer;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Model\Scheduled;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\SortField;

/**
 * Presentation registry: one {@see SectionSpec} per {@see Sections} case. A new
 * section shows up in the UI by adding its spec here; a test fails until then.
 */
final class SectionPresentation
{
    public static function for(Sections $section): SectionSpec
    {
        return match ($section) {
            Sections::EVENTS => new SectionSpec(
                $section,
                'Events',
                'No events in this index',
                'Loom found no dispatched event classes in app/.',
                [
                    self::name(),
                    self::number('handlers', 'Handlers', static fn (Event $e): int => count($e->handledBy), SortField::HANDLER_COUNT),
                    self::number('sites', 'Dispatch sites', static fn (Event $e): int => count($e->dispatchedFrom), SortField::DISPATCH_COUNT),
                    self::file(),
                ],
                EntityKind::EVENT,
                NodeType::EVENT,
                static fn (Event $e): bool => $e->handledBy === [] && $e->dispatchedFrom === [],
                'e',
            ),
            Sections::LISTENERS => new SectionSpec(
                $section,
                'Listeners',
                'No listeners in this index',
                'Loom found no event listeners in app/.',
                [
                    self::name(),
                    self::text('handles', 'Handles', static fn (Listener $l): string => $l->handles === []
                        ? 'unresolved'
                        : implode(', ', array_map(static fn ($h): string => $h->event.'::'.$h->method, $l->handles))),
                    self::text('registration', 'Registration', static fn (Listener $l): string => $l->registration->value),
                    self::number('dispatches', 'Dispatches', static fn (Listener $l): int => count($l->dispatches), SortField::DISPATCH_COUNT),
                    self::file(),
                ],
                EntityKind::LISTENER,
                NodeType::LISTENER,
                static fn (Listener $l): bool => $l->handles === [],
                'l',
            ),
            Sections::OBSERVERS => new SectionSpec(
                $section,
                'Observers',
                'No observers in this index',
                'Loom found no model observers in app/.',
                [
                    self::name(),
                    self::text('model', 'Model', static fn (Observer $o): string => $o->observes),
                    self::text('hooks', 'Hooks', static fn (Observer $o): string => implode(', ', $o->hooks)),
                    self::text('registration', 'Registration', static fn (Observer $o): string => $o->registration->value),
                    self::file(),
                ],
                EntityKind::OBSERVER,
                NodeType::OBSERVER,
                shortcut: 'o',
            ),
            Sections::MODEL_EVENTS => new SectionSpec(
                $section,
                'Model events',
                'No model events in this index',
                'No observer hooks were found on any model.',
                [
                    new ColumnSpec('id', 'Model event', static fn (ModelEvent $m): string => $m->id, ColumnRole::NAME, SortField::NAME),
                    self::text('model', 'Model', static fn (ModelEvent $m): string => $m->model),
                    self::text('event', 'Event', static fn (ModelEvent $m): string => $m->event),
                    self::text('handled_by', 'Handled by', static fn (ModelEvent $m): string => implode(', ', $m->handledBy)),
                ],
            ),
            Sections::JOBS => new SectionSpec(
                $section,
                'Jobs',
                'No jobs in this index',
                'Loom found no job classes in app/.',
                [
                    self::name(),
                    self::text('queue', 'Queue', static fn (Job $j): string => (string) ($j->queueConfig->queue ?? '')),
                    self::text('queued', 'Queued', static fn (Job $j): string => $j->queued ? 'yes' : 'no (sync)'),
                    self::number('sites', 'Dispatch sites', static fn (Job $j): int => count($j->dispatchedFrom)),
                    self::file(),
                ],
                EntityKind::JOB,
                NodeType::JOB,
                shortcut: 'j',
            ),
            Sections::UNRESOLVED_DISPATCHES => new SectionSpec(
                $section,
                'Unresolved dispatches',
                'No unresolved dispatches',
                'Every dispatch in app/ resolved to a class.',
                [
                    new ColumnSpec('location', 'Location', static fn (UnresolvedDispatch $u): string => $u->file.':'.$u->line, ColumnRole::NAME, SortField::FILE),
                    self::text('expression', 'Expression', static fn (UnresolvedDispatch $u): string => $u->expression),
                    self::text('reason', 'Reason', static fn (UnresolvedDispatch $u): string => $u->reason),
                ],
            ),
            Sections::CLOSURE_LISTENERS => new SectionSpec(
                $section,
                'Closure listeners',
                'No closure listeners in this index',
                'Loom found no Event::listen() closures in app/.',
                [
                    new ColumnSpec('location', 'Location', static fn (ClosureListener $c): string => $c->file.':'.$c->line, ColumnRole::NAME, SortField::FILE),
                    self::text('event', 'Event', static fn (ClosureListener $c): string => $c->event),
                    self::text('lines', 'Lines', static fn (ClosureListener $c): string => $c->line.'-'.$c->endLine),
                    self::number('dispatches', 'Dispatches', static fn (ClosureListener $c): int => count($c->dispatches), SortField::DISPATCH_COUNT),
                ],
                null,
                NodeType::CLOSURE,
                shortcut: 'c',
            ),
            Sections::SCHEDULED => new SectionSpec(
                $section,
                'Scheduled',
                'No scheduled tasks in this index',
                'Loom found no tasks in routes/console.php or the Kernel schedule.',
                [
                    new ColumnSpec('name', 'Task', static fn (Scheduled $s): string => $s->name ?? $s->target ?? $s->kind->value, ColumnRole::NAME, SortField::NAME),
                    self::text('kind', 'Kind', static fn (Scheduled $s): string => $s->kind->value),
                    self::text('schedule', 'Schedule', static fn (Scheduled $s): string => $s->cron
                        ?? ($s->frequency !== null ? 'every '.$s->frequency->every.' '.$s->frequency->unit->value : '')),
                    self::text('target', 'Target', static fn (Scheduled $s): string => $s->target ?? ''),
                    self::file(),
                ],
            ),
            Sections::MAILABLES => new SectionSpec(
                $section,
                'Mailables',
                'No mailables in this index',
                'Loom found no mailable classes in app/.',
                [
                    self::name(),
                    self::text('queued', 'Queued', static fn (Mailable $m): string => $m->queued ? 'yes' : 'no'),
                    self::number('sites', 'Send sites', static fn (Mailable $m): int => count($m->sentFrom)),
                    self::file(),
                ],
                EntityKind::MAILABLE,
                NodeType::MAILABLE,
                shortcut: 'm',
            ),
            Sections::NOTIFICATIONS => new SectionSpec(
                $section,
                'Notifications',
                'No notifications in this index',
                'Loom found no notification classes in app/.',
                [
                    self::name(),
                    self::text('channels', 'Channels', static fn (Notification $n): string => $n->channelsDynamic ? 'dynamic' : implode(', ', $n->channels)),
                    self::number('sites', 'Send sites', static fn (Notification $n): int => count($n->notifiedFrom)),
                    self::file(),
                ],
                EntityKind::NOTIFICATION,
                NodeType::NOTIFICATION,
                shortcut: 'n',
            ),
            Sections::ROUTES => new SectionSpec(
                $section,
                'Routes',
                'No routes in this index',
                'Loom found no routes in routes/.',
                [
                    new ColumnSpec('method', 'Method', static fn (Route $r): string => $r->method, ColumnRole::TEXT),
                    new ColumnSpec('uri', 'URI', static fn (Route $r): string => '/'.ltrim($r->uri, '/'), ColumnRole::NAME, SortField::URI),
                    self::text('action', 'Action', static fn (Route $r): string => $r->controllerFqcn === null
                        ? 'closure'
                        : $r->controllerFqcn.'::'.($r->controllerMethod ?? '__invoke')),
                    self::text('middleware', 'Middleware', static fn (Route $r): string => implode(', ', $r->middleware)),
                    self::file(),
                ],
                null,
                NodeType::ROUTE,
                shortcut: 'r',
            ),
        };
    }

    /** @return list<SectionSpec> */
    public static function all(): array
    {
        return array_map(self::for(...), Sections::cases());
    }

    private static function name(): ColumnSpec
    {
        return new ColumnSpec('name', 'Name', static fn (object $i): string => is_string($i->fqcn ?? null) ? $i->fqcn : '', ColumnRole::NAME, SortField::NAME);
    }

    private static function file(): ColumnSpec
    {
        return new ColumnSpec('file', 'File', static fn (object $i): string => is_string($i->file ?? null) ? $i->file.':'.(is_int($i->line ?? null) ? $i->line : 0) : '', ColumnRole::FILE, SortField::FILE);
    }

    private static function text(string $key, string $label, \Closure $value): ColumnSpec
    {
        return new ColumnSpec($key, $label, $value);
    }

    private static function number(string $key, string $label, \Closure $value, ?SortField $sort = null): ColumnSpec
    {
        return new ColumnSpec($key, $label, $value, ColumnRole::NUMBER, $sort);
    }
}
