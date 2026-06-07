<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\FrequencyUnit;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\Model\ClosureListener;
use Lucasp\Loom\Index\Model\Dispatch;
use Lucasp\Loom\Index\Model\DispatchOverrides;
use Lucasp\Loom\Index\Model\DispatchSite;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Frequency;
use Lucasp\Loom\Index\Model\Handle;
use Lucasp\Loom\Index\Model\Handler;
use Lucasp\Loom\Index\Model\Job;
use Lucasp\Loom\Index\Model\Listener;
use Lucasp\Loom\Index\Model\Mailable;
use Lucasp\Loom\Index\Model\ModelEvent;
use Lucasp\Loom\Index\Model\Notification;
use Lucasp\Loom\Index\Model\Observer;
use Lucasp\Loom\Index\Model\QueueConfig;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Model\Scheduled;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;
use Lucasp\Loom\Index\ObserverRegistration;
use Lucasp\Loom\Index\ScheduleKind;

/**
 * A representative decoded-index array: every one of the 11 sections carries at
 * least one schema-faithful entry, with nested dispatched_from / handled_by /
 * dispatches / queue_config / overrides so the typed getters are exercised in
 * full. Shared with {@see IndexLoaderTest} (Pest loads every test file, so a
 * top-level function declared here is globally available).
 *
 * @return array<string, mixed>
 */
function representativeIndexArray(): array
{
    return [
        'loom_version' => '0.3.0',
        'scanned_at' => '2026-06-07T12:00:00+00:00',
        'laravel_version' => '12.x',
        'events' => [
            [
                'id' => 'event:App\\Events\\OrderShipped',
                'fqcn' => 'App\\Events\\OrderShipped',
                'kind' => 'event',
                'file' => 'app/Events/OrderShipped.php',
                'line' => 12,
                'dispatched_from' => [
                    [
                        'file' => 'app/Services/Shipping.php',
                        'line' => 40,
                        'method' => 'ship',
                        'overrides' => null,
                        'channels' => null,
                    ],
                ],
                'handled_by' => [
                    ['listener' => 'App\\Listeners\\NotifyCustomer', 'method' => 'handle'],
                ],
            ],
        ],
        'model_events' => [
            [
                'id' => 'model_event:App\\Models\\User:created',
                'kind' => 'model_event',
                'model' => 'App\\Models\\User',
                'event' => 'created',
                'handled_by' => ['App\\Observers\\UserObserver'],
            ],
        ],
        'listeners' => [
            [
                'fqcn' => 'App\\Listeners\\NotifyCustomer',
                'file' => 'app/Listeners/NotifyCustomer.php',
                'line' => 9,
                'handles' => [
                    ['event' => 'App\\Events\\OrderShipped', 'method' => 'handle'],
                ],
                'registration' => 'auto_discovered',
                'queued' => true,
                'dispatches' => [
                    [
                        'target' => 'App\\Jobs\\SendInvoice',
                        'kind' => 'job',
                        'confidence' => 'high',
                        'file' => 'app/Listeners/NotifyCustomer.php',
                        'line' => 15,
                    ],
                ],
            ],
        ],
        'observers' => [
            [
                'fqcn' => 'App\\Observers\\UserObserver',
                'file' => 'app/Observers/UserObserver.php',
                'line' => 7,
                'observes' => 'App\\Models\\User',
                'registration' => 'attribute',
                'hooks' => ['created', 'updated'],
                'dispatches' => [
                    [
                        'target' => 'App\\Events\\OrderShipped',
                        'kind' => 'event',
                        'confidence' => 'medium',
                        'file' => 'app/Observers/UserObserver.php',
                        'line' => 11,
                    ],
                ],
            ],
        ],
        'jobs' => [
            [
                'fqcn' => 'App\\Jobs\\SendInvoice',
                'file' => 'app/Jobs/SendInvoice.php',
                'line' => 14,
                'queued' => true,
                'queue_config' => [
                    'connection' => 'redis',
                    'queue' => 'invoices',
                    'delay' => 60,
                    'tries' => 3,
                    'timeout' => 120,
                    'backoff' => 10,
                ],
                'dispatched_from' => [
                    [
                        'file' => 'app/Listeners/NotifyCustomer.php',
                        'line' => 15,
                        'method' => 'handle',
                        'overrides' => null,
                        'channels' => null,
                    ],
                ],
                'dispatches' => [],
            ],
        ],
        'unresolved_dispatches' => [
            [
                'file' => 'app/Services/Dynamic.php',
                'line' => 22,
                'expression' => 'event($event)',
                'reason' => 'dynamic_variable',
            ],
        ],
        'closure_listeners' => [
            [
                'event' => 'App\\Events\\OrderShipped',
                'file' => 'app/Providers/EventServiceProvider.php',
                'line' => 30,
                'end_line' => 34,
                'registration' => 'event_listen_call',
                'queued' => false,
                'dispatches' => [
                    [
                        'target' => 'App\\Jobs\\SendInvoice',
                        'kind' => 'job',
                        'confidence' => 'low',
                        'file' => 'app/Providers/EventServiceProvider.php',
                        'line' => 32,
                    ],
                ],
            ],
        ],
        'scheduled' => [
            [
                'kind' => 'command',
                'name' => 'reports:daily',
                'target' => 'reports:daily',
                'arguments' => ['--force', 'tenant=1'],
                'queue' => 'reports',
                'connection' => 'redis',
                'cron' => '0 8 * * *',
                'frequency' => null,
                'timezone' => 'UTC',
                'without_overlapping' => true,
                'without_overlapping_expires_at' => 10,
                'on_one_server' => true,
                'run_in_background' => false,
                'even_in_maintenance_mode' => false,
                'constraints' => ['weekdays'],
                'file' => 'app/Console/Kernel.php',
                'line' => 18,
            ],
            [
                'kind' => 'closure',
                'name' => null,
                'target' => null,
                'arguments' => [],
                'queue' => null,
                'connection' => null,
                'cron' => null,
                'frequency' => ['unit' => 'seconds', 'every' => 10],
                'timezone' => null,
                'without_overlapping' => false,
                'without_overlapping_expires_at' => null,
                'on_one_server' => false,
                'run_in_background' => false,
                'even_in_maintenance_mode' => false,
                'constraints' => [],
                'file' => 'app/Console/Kernel.php',
                'line' => 24,
            ],
        ],
        'routes' => [
            [
                'method' => 'GET',
                'uri' => 'orders/{order}',
                'name' => 'orders.show',
                'controller_fqcn' => 'App\\Http\\Controllers\\OrderController',
                'controller_method' => 'show',
                'middleware' => ['web', 'auth'],
                'file' => 'routes/web.php',
                'line' => 11,
                'dispatches' => [
                    [
                        'target' => 'App\\Events\\OrderShipped',
                        'kind' => 'event',
                        'confidence' => 'high',
                        'file' => 'app/Http/Controllers/OrderController.php',
                        'line' => 20,
                    ],
                ],
            ],
        ],
        'mailables' => [
            [
                'fqcn' => 'App\\Mail\\InvoicePaid',
                'file' => 'app/Mail/InvoicePaid.php',
                'line' => 13,
                'queued' => false,
                'queue_config' => null,
                'sent_from' => [
                    [
                        'file' => 'app/Services/Billing.php',
                        'line' => 50,
                        'method' => 'send',
                        'overrides' => [
                            'locale' => 'en',
                            'mailer' => 'ses',
                            'connection' => null,
                            'queue' => null,
                            'delay' => null,
                            'after_commit' => true,
                        ],
                        'channels' => null,
                    ],
                ],
            ],
        ],
        'notifications' => [
            [
                'fqcn' => 'App\\Notifications\\OrderShipped',
                'file' => 'app/Notifications/OrderShipped.php',
                'line' => 16,
                'queued' => true,
                'queue_config' => null,
                'notified_from' => [
                    [
                        'file' => 'app/Services/Shipping.php',
                        'line' => 60,
                        'method' => 'notify',
                        'overrides' => null,
                        'channels' => ['mail', 'database'],
                    ],
                ],
                'channels' => ['mail', 'database'],
                'channels_dynamic' => false,
            ],
        ],
    ];
}

it('hydrates every section into correctly-typed read-model value objects', function () {
    $index = (new IndexLoader)->fromArray(representativeIndexArray());

    expect($index->events())->toHaveCount(1)->each->toBeInstanceOf(Event::class);
    expect($index->modelEvents())->toHaveCount(1)->each->toBeInstanceOf(ModelEvent::class);
    expect($index->listeners())->toHaveCount(1)->each->toBeInstanceOf(Listener::class);
    expect($index->observers())->toHaveCount(1)->each->toBeInstanceOf(Observer::class);
    expect($index->jobs())->toHaveCount(1)->each->toBeInstanceOf(Job::class);
    expect($index->unresolvedDispatches())->toHaveCount(1)->each->toBeInstanceOf(UnresolvedDispatch::class);
    expect($index->closureListeners())->toHaveCount(1)->each->toBeInstanceOf(ClosureListener::class);
    expect($index->scheduled())->toHaveCount(2)->each->toBeInstanceOf(Scheduled::class);
    expect($index->routes())->toHaveCount(1)->each->toBeInstanceOf(Route::class);
    expect($index->mailables())->toHaveCount(1)->each->toBeInstanceOf(Mailable::class);
    expect($index->notifications())->toHaveCount(1)->each->toBeInstanceOf(Notification::class);
});

it('hydrates an event with its dispatch sites and handlers', function () {
    $event = (new IndexLoader)->fromArray(representativeIndexArray())->events()[0];

    expect($event->id)->toBe('event:App\\Events\\OrderShipped');
    expect($event->fqcn)->toBe('App\\Events\\OrderShipped');
    expect($event->kind)->toBe('event');
    expect($event->file)->toBe('app/Events/OrderShipped.php');
    expect($event->line)->toBe(12);

    expect($event->dispatchedFrom)->toHaveCount(1);
    expect($event->dispatchedFrom[0])->toBeInstanceOf(DispatchSite::class);
    expect($event->dispatchedFrom[0]->method)->toBe('ship');

    expect($event->handledBy)->toHaveCount(1);
    expect($event->handledBy[0])->toBeInstanceOf(Handler::class);
    expect($event->handledBy[0]->listener)->toBe('App\\Listeners\\NotifyCustomer');
    expect($event->handledBy[0]->method)->toBe('handle');
});

it('hydrates a model event', function () {
    $modelEvent = (new IndexLoader)->fromArray(representativeIndexArray())->modelEvents()[0];

    expect($modelEvent->id)->toBe('model_event:App\\Models\\User:created');
    expect($modelEvent->model)->toBe('App\\Models\\User');
    expect($modelEvent->event)->toBe('created');
    expect($modelEvent->handledBy)->toBe(['App\\Observers\\UserObserver']);
});

it('hydrates a listener with enum registration and nested dispatches', function () {
    $listener = (new IndexLoader)->fromArray(representativeIndexArray())->listeners()[0];

    expect($listener->fqcn)->toBe('App\\Listeners\\NotifyCustomer');
    expect($listener->registration)->toBe(ListenerRegistration::AUTO_DISCOVERED);
    expect($listener->queued)->toBeTrue();

    expect($listener->handles)->toHaveCount(1);
    expect($listener->handles[0])->toBeInstanceOf(Handle::class);
    expect($listener->handles[0]->event)->toBe('App\\Events\\OrderShipped');
    expect($listener->handles[0]->method)->toBe('handle');

    expect($listener->dispatches)->toHaveCount(1);
    expect($listener->dispatches[0])->toBeInstanceOf(Dispatch::class);
    expect($listener->dispatches[0]->target)->toBe('App\\Jobs\\SendInvoice');
    expect($listener->dispatches[0]->kind)->toBe(DispatchKinds::JOB);
    expect($listener->dispatches[0]->confidence)->toBe(Confidence::HIGH);
    expect($listener->dispatches[0]->line)->toBe(15);
});

it('hydrates an observer with an attribute registration and lifecycle hooks', function () {
    $observer = (new IndexLoader)->fromArray(representativeIndexArray())->observers()[0];

    expect($observer->fqcn)->toBe('App\\Observers\\UserObserver');
    expect($observer->observes)->toBe('App\\Models\\User');
    expect($observer->registration)->toBe(ObserverRegistration::ATTRIBUTE);
    expect($observer->hooks)->toBe(['created', 'updated']);
    expect($observer->dispatches[0]->confidence)->toBe(Confidence::MEDIUM);
});

it('hydrates a closure listener span and enum registration', function () {
    $closure = (new IndexLoader)->fromArray(representativeIndexArray())->closureListeners()[0];

    expect($closure->event)->toBe('App\\Events\\OrderShipped');
    expect($closure->line)->toBe(30);
    expect($closure->endLine)->toBe(34);
    expect($closure->registration)->toBe(ListenerRegistration::EVENT_LISTEN_CALL);
    expect($closure->queued)->toBeFalse();
    expect($closure->dispatches[0]->confidence)->toBe(Confidence::LOW);
});

it('hydrates a scheduled entry with kind enum and modifiers', function () {
    $scheduled = (new IndexLoader)->fromArray(representativeIndexArray())->scheduled()[0];

    expect($scheduled->kind)->toBe(ScheduleKind::COMMAND);
    expect($scheduled->name)->toBe('reports:daily');
    expect($scheduled->target)->toBe('reports:daily');
    expect($scheduled->arguments)->toBe(['--force', 'tenant=1']);
    expect($scheduled->queue)->toBe('reports');
    expect($scheduled->connection)->toBe('redis');
    expect($scheduled->cron)->toBe('0 8 * * *');
    expect($scheduled->frequency)->toBeNull();
    expect($scheduled->timezone)->toBe('UTC');
    expect($scheduled->withoutOverlapping)->toBeTrue();
    expect($scheduled->withoutOverlappingExpiresAt)->toBe(10);
    expect($scheduled->onOneServer)->toBeTrue();
    expect($scheduled->runInBackground)->toBeFalse();
    expect($scheduled->evenInMaintenanceMode)->toBeFalse();
    expect($scheduled->constraints)->toBe(['weekdays']);
    expect($scheduled->line)->toBe(18);
});

it('hydrates a sub-minute frequency into a Frequency value object', function () {
    $scheduled = (new IndexLoader)->fromArray(representativeIndexArray())->scheduled()[1];

    expect($scheduled->cron)->toBeNull();
    expect($scheduled->frequency)->toBeInstanceOf(Frequency::class);
    expect($scheduled->frequency->unit)->toBe(FrequencyUnit::SECONDS);
    expect($scheduled->frequency->every)->toBe(10);
});

it('hydrates a route with controller target, middleware, and dispatches', function () {
    $route = (new IndexLoader)->fromArray(representativeIndexArray())->routes()[0];

    expect($route->method)->toBe('GET');
    expect($route->uri)->toBe('orders/{order}');
    expect($route->name)->toBe('orders.show');
    expect($route->controllerFqcn)->toBe('App\\Http\\Controllers\\OrderController');
    expect($route->controllerMethod)->toBe('show');
    expect($route->middleware)->toBe(['web', 'auth']);
    expect($route->dispatches)->toHaveCount(1);
    expect($route->dispatches[0]->target)->toBe('App\\Events\\OrderShipped');
    expect($route->dispatches[0]->kind)->toBe(DispatchKinds::EVENT);
});

it('hydrates an unresolved dispatch', function () {
    $unresolved = (new IndexLoader)->fromArray(representativeIndexArray())->unresolvedDispatches()[0];

    expect($unresolved->file)->toBe('app/Services/Dynamic.php');
    expect($unresolved->line)->toBe(22);
    expect($unresolved->expression)->toBe('event($event)');
    expect($unresolved->reason)->toBe('dynamic_variable');
});

it('round-trips the section payloads through toArray, recomputing the envelope', function () {
    $source = representativeIndexArray();
    $index = (new IndexLoader)->fromArray($source);
    $out = $index->toArray();

    // Meta envelope is preserved verbatim.
    expect($out['loom_version'])->toBe('0.3.0');
    expect($out['scanned_at'])->toBe('2026-06-07T12:00:00+00:00');
    expect($out['laravel_version'])->toBe('12.x');

    // Section payloads survive the round-trip untouched.
    foreach (['events', 'model_events', 'listeners', 'observers', 'jobs', 'unresolved_dispatches', 'closure_listeners', 'scheduled', 'routes', 'mailables', 'notifications'] as $section) {
        expect($out[$section])->toBe($source[$section]);
    }

    // Stats are recomputed from the section counts, not carried from input.
    expect($out['stats']['events'])->toBe(1);
    expect($out['stats']['listeners'])->toBe(1);
    expect($out['stats']['jobs'])->toBe(1);
    expect($out['stats'])->not->toHaveKey('model_events');
});

it('hydrates a dispatch site with overrides and channels', function () {
    $mailable = (new IndexLoader)->fromArray(representativeIndexArray())->mailables()[0];
    $site = $mailable->sentFrom[0];

    expect($site)->toBeInstanceOf(DispatchSite::class);
    expect($site->method)->toBe('send');
    expect($site->overrides)->toBeInstanceOf(DispatchOverrides::class);
    expect($site->overrides->locale)->toBe('en');
    expect($site->overrides->mailer)->toBe('ses');
    expect($site->overrides->afterCommit)->toBeTrue();
    expect($site->overrides->connection)->toBeNull();
    expect($site->channels)->toBeNull();

    $notification = (new IndexLoader)->fromArray(representativeIndexArray())->notifications()[0];
    $notifiedSite = $notification->notifiedFrom[0];

    expect($notifiedSite->overrides)->toBeNull();
    expect($notifiedSite->channels)->toBe(['mail', 'database']);
});

it('leaves overrides and channels null on a bare dispatch site', function () {
    $event = (new IndexLoader)->fromArray(representativeIndexArray())->events()[0];
    $site = $event->dispatchedFrom[0];

    expect($site->overrides)->toBeNull();
    expect($site->channels)->toBeNull();
});

it('hydrates a present queue config on a job', function () {
    $job = (new IndexLoader)->fromArray(representativeIndexArray())->jobs()[0];

    expect($job->queueConfig)->toBeInstanceOf(QueueConfig::class);
    expect($job->queueConfig->connection)->toBe('redis');
    expect($job->queueConfig->queue)->toBe('invoices');
    expect($job->queueConfig->delay)->toBe(60);
    expect($job->queueConfig->tries)->toBe(3);
    expect($job->queueConfig->timeout)->toBe(120);
    expect($job->queueConfig->backoff)->toBe(10);
});

it('leaves the queue config null when absent on a mailable', function () {
    $mailable = (new IndexLoader)->fromArray(representativeIndexArray())->mailables()[0];

    expect($mailable->queued)->toBeFalse();
    expect($mailable->queueConfig)->toBeNull();
});

it('resolves lookups by fqcn and returns null for unknown classes', function () {
    $index = (new IndexLoader)->fromArray(representativeIndexArray());

    expect($index->findEvent('App\\Events\\OrderShipped'))->toBeInstanceOf(Event::class);
    expect($index->findListener('App\\Listeners\\NotifyCustomer'))->toBeInstanceOf(Listener::class);
    expect($index->findObserver('App\\Observers\\UserObserver'))->toBeInstanceOf(Observer::class);
    expect($index->findJob('App\\Jobs\\SendInvoice'))->toBeInstanceOf(Job::class);
    expect($index->findMailable('App\\Mail\\InvoicePaid'))->toBeInstanceOf(Mailable::class);
    expect($index->findNotification('App\\Notifications\\OrderShipped'))->toBeInstanceOf(Notification::class);

    expect($index->findEvent('App\\Events\\Nope'))->toBeNull();
    expect($index->findListener('App\\Listeners\\Nope'))->toBeNull();
    expect($index->findObserver('App\\Observers\\Nope'))->toBeNull();
    expect($index->findJob('App\\Jobs\\Nope'))->toBeNull();
    expect($index->findMailable('App\\Mail\\Nope'))->toBeNull();
    expect($index->findNotification('App\\Notifications\\Nope'))->toBeNull();
});

it('returns an events dispatchers and handlers by fqcn', function () {
    $index = (new IndexLoader)->fromArray(representativeIndexArray());

    $dispatchers = $index->dispatchersOf('App\\Events\\OrderShipped');
    expect($dispatchers)->toHaveCount(1);
    expect($dispatchers[0])->toBeInstanceOf(DispatchSite::class);
    expect($dispatchers[0]->method)->toBe('ship');

    $handlers = $index->handlersOf('App\\Events\\OrderShipped');
    expect($handlers)->toHaveCount(1);
    expect($handlers[0])->toBeInstanceOf(Handler::class);
    expect($handlers[0]->listener)->toBe('App\\Listeners\\NotifyCustomer');
});

it('returns empty lists for dispatchers and handlers of an unknown event', function () {
    $index = (new IndexLoader)->fromArray(representativeIndexArray());

    expect($index->dispatchersOf('App\\Events\\Nope'))->toBe([]);
    expect($index->handlersOf('App\\Events\\Nope'))->toBe([]);
});
