<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use Lucasp\Loom\Index\Sections;

/**
 * Entities addressable by FQCN through {@see IndexQuery::entity()}.
 */
enum EntityKind: string
{
    case EVENT = 'event';
    case LISTENER = 'listener';
    case OBSERVER = 'observer';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';

    /** The index section this kind is listed under. */
    public function section(): Sections
    {
        return match ($this) {
            self::EVENT => Sections::EVENTS,
            self::LISTENER => Sections::LISTENERS,
            self::OBSERVER => Sections::OBSERVERS,
            self::JOB => Sections::JOBS,
            self::MAILABLE => Sections::MAILABLES,
            self::NOTIFICATION => Sections::NOTIFICATIONS,
        };
    }

    public static function forSection(Sections $section): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->section() === $section) {
                return $kind;
            }
        }

        return null;
    }
}
