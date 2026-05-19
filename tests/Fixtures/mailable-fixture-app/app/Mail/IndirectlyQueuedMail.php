<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Inherits ShouldQueue from AbstractQueuedMail (which implements it directly).
 * Used to verify MailableScanner detects indirect ShouldQueue via the resolver.
 */
class IndirectlyQueuedMail extends AbstractQueuedMail
{
    public string $queue = 'mail-indirect';
}
