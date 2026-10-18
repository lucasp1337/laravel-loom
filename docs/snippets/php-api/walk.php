<?php

$event = 'App\Events\OrderPlaced';

foreach ($index->dispatchersOf($event) as $site) {
    echo "dispatched from {$site->method}\n";
}

foreach ($index->handlersOf($event) as $handler) {
    echo "handled by {$handler->listener}::{$handler->method}\n";

    foreach ($index->findListener($handler->listener)?->dispatches ?? [] as $dispatch) {
        echo "  dispatches {$dispatch->kind->value} {$dispatch->target}\n";
    }
}
