<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire {name?}', fn () => 1)->purpose('Inspire')->hourly();

Artisan::command('plain', fn () => 1)->purpose('Not scheduled');
