<?php

$listener = $index->findListener('App\Listeners\SendReceipt');

echo "{$listener->file}:{$listener->line}\n";
echo $listener->registration->value, "\n";

var_dump($index->findListener('App\Listeners\Missing'));
