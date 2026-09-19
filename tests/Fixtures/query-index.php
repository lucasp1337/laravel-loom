<?php

declare(strict_types=1);

/**
 * Shared index payload for the MCP characterization and IndexQuery tests.
 *
 *   POST /orders → OrderController::store dispatches OrderPlaced (twice, same site)
 *   OrderPlaced  → SendReceipt (dispatches ReceiptSent + job SendMail)
 *   ReceiptSent  → ArchiveReceipt, plus a closure listener that dispatches Ping
 *   Ping ⇄ Pong  → PingListener / PongListener dispatch each other (cycle)
 *   Lonely       → orphan event; Idle → listener handling nothing
 */

$site = static fn (string $file, int $line, string $method = 'dispatch'): array => [
    'file' => $file, 'line' => $line, 'method' => $method,
];

$dispatch = static fn (string $target, string $file, int $line = 7, string $kind = 'event'): array => [
    'target' => $target, 'kind' => $kind, 'confidence' => 'high', 'file' => $file, 'line' => $line,
];

$event = static fn (string $name, array $handledBy, array $dispatchedFrom): array => [
    'id' => "App\\Events\\{$name}",
    'fqcn' => "App\\Events\\{$name}",
    'kind' => 'class',
    'file' => "app/Events/{$name}.php",
    'line' => 10,
    'handled_by' => $handledBy,
    'dispatched_from' => $dispatchedFrom,
];

$listener = static fn (string $name, string $handles, array $dispatches, bool $queued = false): array => [
    'fqcn' => "App\\Listeners\\{$name}",
    'file' => "app/Listeners/{$name}.php",
    'line' => 5,
    'registration' => 'auto_discovered',
    'queued' => $queued,
    'handles' => $handles === '' ? [] : [['event' => "App\\Events\\{$handles}", 'method' => 'handle']],
    'dispatches' => $dispatches,
];

$handler = static fn (string $name): array => ['listener' => "App\\Listeners\\{$name}", 'method' => 'handle'];

return [
    'schema_version' => '1.0',
    'loom_version' => '0.3.0',
    'scanned_at' => '2026-01-01T00:00:00+00:00',
    'laravel_version' => '12.x',
    'events' => [
        $event('OrderPlaced', [$handler('SendReceipt')], [$site('app/Http/Controllers/OrderController.php', 20)]),
        $event('ReceiptSent', [$handler('ArchiveReceipt')], [$site('app/Listeners/SendReceipt.php', 7)]),
        $event('Ping', [$handler('PingListener')], [$site('app/Listeners/PongListener.php', 7)]),
        $event('Pong', [$handler('PongListener')], [$site('app/Listeners/PingListener.php', 7)]),
        $event('Lonely', [], []),
    ],
    'listeners' => [
        $listener('SendReceipt', 'OrderPlaced', [
            $dispatch('App\\Events\\ReceiptSent', 'app/Listeners/SendReceipt.php'),
            $dispatch('App\\Jobs\\SendMail', 'app/Listeners/SendReceipt.php', 9, 'job'),
        ]),
        $listener('ArchiveReceipt', 'ReceiptSent', []),
        $listener('Idle', '', []),
        $listener('PingListener', 'Ping', [$dispatch('App\\Events\\Pong', 'app/Listeners/PingListener.php')], true),
        $listener('PongListener', 'Pong', [$dispatch('App\\Events\\Ping', 'app/Listeners/PongListener.php')]),
    ],
    'closure_listeners' => [
        [
            'event' => 'App\\Events\\ReceiptSent',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 30,
            'end_line' => 34,
            'registration' => 'event_listen_call',
            'queued' => true,
            'dispatches' => [$dispatch('App\\Events\\Ping', 'app/Providers/EventServiceProvider.php', 32)],
        ],
    ],
    'observers' => [
        [
            'fqcn' => 'App\\Observers\\OrderObserver',
            'file' => 'app/Observers/OrderObserver.php',
            'line' => 8,
            'observes' => 'App\\Models\\Order',
            'registration' => 'observe_call',
            'hooks' => ['created'],
            'dispatches' => [$dispatch('App\\Events\\OrderPlaced', 'app/Observers/OrderObserver.php', 12)],
        ],
    ],
    'model_events' => [
        ['id' => 'App\\Models\\Order.created', 'model' => 'App\\Models\\Order', 'event' => 'created', 'handled_by' => [['handler' => 'App\\Observers\\OrderObserver', 'method' => 'created', 'file' => 'app/Observers/OrderObserver.php', 'line' => 5]]],
    ],
    'jobs' => [
        [
            'fqcn' => 'App\\Jobs\\SendMail',
            'file' => 'app/Jobs/SendMail.php',
            'line' => 6,
            'queued' => true,
            'queue_config' => null,
            'dispatched_from' => [$site('app/Listeners/SendReceipt.php', 9)],
            'dispatches' => [$dispatch('App\\Events\\Pong', 'app/Jobs/SendMail.php', 15)],
        ],
    ],
    'mailables' => [
        [
            'fqcn' => 'App\\Mail\\Receipt',
            'file' => 'app/Mail/Receipt.php',
            'line' => 4,
            'queued' => false,
            'queue_config' => null,
            'sent_from' => [$site('app/Jobs/SendMail.php', 20, 'handle')],
        ],
    ],
    'notifications' => [
        [
            'fqcn' => 'App\\Notifications\\Shipped',
            'file' => 'app/Notifications/Shipped.php',
            'line' => 4,
            'queued' => false,
            'queue_config' => null,
            'notified_from' => [],
            'channels' => ['mail'],
            'channels_dynamic' => false,
        ],
    ],
    'scheduled' => [],
    'routes' => [
        [
            'method' => 'POST',
            'uri' => 'orders',
            'name' => 'orders.store',
            'controller_fqcn' => 'App\\Http\\Controllers\\OrderController',
            'controller_method' => 'store',
            'middleware' => ['web'],
            'file' => 'routes/web.php',
            'line' => 12,
            'dispatches' => [
                $dispatch('App\\Events\\OrderPlaced', 'app/Http/Controllers/OrderController.php', 20),
                $dispatch('App\\Events\\OrderPlaced', 'app/Http/Controllers/OrderController.php', 20),
                $dispatch('App\\Jobs\\SendMail', 'app/Http/Controllers/OrderController.php', 22, 'job'),
            ],
        ],
        [
            'method' => 'GET',
            'uri' => 'health',
            'name' => null,
            'controller_fqcn' => null,
            'controller_method' => null,
            'middleware' => [],
            'file' => 'routes/web.php',
            'line' => 3,
            'dispatches' => [],
        ],
        [
            'method' => 'GET',
            'uri' => '/ping',
            'name' => 'ping',
            'controller_fqcn' => 'App\\Http\\Controllers\\PingController',
            'controller_method' => null,
            'middleware' => [],
            'file' => 'routes/web.php',
            'line' => 5,
            'dispatches' => [$dispatch('App\\Events\\Ping', 'app/Http/Controllers/PingController.php', 11)],
        ],
    ],
    'unresolved_dispatches' => [
        ['file' => 'app/A.php', 'line' => 10, 'expression' => 'dispatch($job)', 'reason' => 'dynamic_class_name'],
    ],
    'stats' => [],
];
