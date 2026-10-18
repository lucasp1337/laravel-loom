<?php

use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\IndexLoadException;

try {
    (new IndexLoader)->fromJson('{"schema_version": "2.0"}');
} catch (IndexLoadException $e) {
    echo $e->getMessage(), "\n";
}
