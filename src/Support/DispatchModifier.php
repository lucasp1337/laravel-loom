<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Fluent dispatch methods whose literal argument becomes a dispatch override
 * (`$defs/dispatchOverrides`). The case value is the method name as written in
 * the chain.
 *
 * @internal
 */
enum DispatchModifier: string
{
    /** `->locale('fr')` on a mailable or notification */
    case LOCALE = 'locale';

    /** `->mailer('postmark')` on a mailable */
    case MAILER = 'mailer';

    /** `->onConnection('redis')` */
    case CONNECTION = 'onConnection';

    /** `->onQueue('emails')` */
    case QUEUE = 'onQueue';

    /** `->delay(60)`: integer-literal seconds only; `now()->addMinutes(5)` and variables are not captured */
    case DELAY = 'delay';

    /** `->afterCommit()`: presence implies true, false is never emitted */
    case AFTER_COMMIT = 'afterCommit';
}
