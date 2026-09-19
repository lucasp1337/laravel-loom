<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Sections;

/**
 * Entity type as the UI presents it: badge glyph, label and CSS modifier.
 *
 * @internal
 */
enum NodeType: string
{
    case EVENT = 'event';
    case LISTENER = 'listener';
    case OBSERVER = 'observer';
    case CLOSURE = 'closure';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';
    case ROUTE = 'route';
    case CYCLE = 'cycle';

    public function glyph(): string
    {
        return match ($this) {
            self::EVENT => 'E',
            self::LISTENER => 'L',
            self::OBSERVER => 'O',
            self::CLOSURE => 'C',
            self::JOB => 'J',
            self::MAILABLE => 'M',
            self::NOTIFICATION => 'N',
            self::ROUTE => 'R',
            self::CYCLE => "\u{21BA}",
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function forDispatch(DispatchKinds $kind): self
    {
        return match ($kind) {
            DispatchKinds::EVENT => self::EVENT,
            DispatchKinds::JOB, DispatchKinds::AMBIGUOUS => self::JOB,
            DispatchKinds::MAILABLE => self::MAILABLE,
            DispatchKinds::NOTIFICATION => self::NOTIFICATION,
        };
    }

    public static function forSection(Sections $section): ?self
    {
        return match ($section) {
            Sections::EVENTS => self::EVENT,
            Sections::LISTENERS => self::LISTENER,
            Sections::OBSERVERS => self::OBSERVER,
            Sections::CLOSURE_LISTENERS => self::CLOSURE,
            Sections::JOBS => self::JOB,
            Sections::MAILABLES => self::MAILABLE,
            Sections::NOTIFICATIONS => self::NOTIFICATION,
            Sections::ROUTES => self::ROUTE,
            default => null,
        };
    }
}
