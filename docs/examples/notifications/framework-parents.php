<?php

// Framework notification parents

// app/Notifications/PasswordReset.php
namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class PasswordReset extends ResetPassword {} // → notifications[PasswordReset]

// app/Notifications/ConfirmEmail.php
namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;

class ConfirmEmail extends VerifyEmail {} // → notifications[ConfirmEmail].channels []

// app/Notifications/Formatter.php
namespace App\Notifications;

use Illuminate\Support\Collection;

class Formatter extends Collection {} // ✗ not recorded
