<?php

// Parents in vendor/

// app/Jobs/ImportCatalog.php
namespace App\Jobs;

use Illuminate\Queue\CallQueuedClosure;

class ImportCatalog extends CallQueuedClosure {} // → jobs.queued false

// app/Listeners/SyncSearch.php
namespace App\Listeners;

use Illuminate\Auth\Listeners\SendEmailVerificationNotification;

class SyncSearch extends SendEmailVerificationNotification {} // ✗ not recorded

// app/Notifications/PasswordReset.php
namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class PasswordReset extends ResetPassword {} // ✗ not recorded

// app/Notifications/BackupFailed.php
namespace App\Notifications;

use Spatie\Backup\Notifications\BaseNotification;

class BackupFailed extends BaseNotification {} // → notifications.channels [] → notifications.channels_dynamic false
