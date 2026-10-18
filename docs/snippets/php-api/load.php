<?php

use Lucasp\Loom\Index\IndexLoader;

$index = (new IndexLoader)->fromFile('storage/loom/index.json');

echo $index->schemaVersion, "\n";
echo count($index->events()), ' events, ', count($index->routes()), " routes\n";
