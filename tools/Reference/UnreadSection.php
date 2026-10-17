<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Lucasp\Loom\Index\UnresolvedReason;

/** The groups the "forms Loom does not read" page sorts reasons into. */
enum UnreadSection
{
    case DispatchTargets;
    case ClassLookup;
    case ListenerRegistrations;
    case ModelHooks;

    public static function of(UnresolvedReason $reason): self
    {
        return match ($reason) {
            UnresolvedReason::DYNAMIC_CLASS_NAME,
            UnresolvedReason::CONTAINER_RESOLUTION,
            UnresolvedReason::STRING_CONCATENATION,
            UnresolvedReason::CONDITIONAL_DISPATCH,
            UnresolvedReason::DYNAMIC_LIST,
            UnresolvedReason::STRING_EVENT_NAME,
            UnresolvedReason::CLOSURE_JOB,
            UnresolvedReason::VIEW_MAIL,
            UnresolvedReason::CALL_RESULT,
            UnresolvedReason::STORED_TARGET,
            UnresolvedReason::RELATIVE_CLASS_NAME,
            UnresolvedReason::UNSUPPORTED_EXPRESSION => self::DispatchTargets,
            UnresolvedReason::CLASS_NOT_FOUND,
            UnresolvedReason::TARGET_NOT_INDEXED => self::ClassLookup,
            UnresolvedReason::UNREADABLE_EVENT_NAME,
            UnresolvedReason::UNTYPED_CLOSURE_LISTENER,
            UnresolvedReason::UNREADABLE_LISTENER,
            UnresolvedReason::UNREADABLE_SUBSCRIBER => self::ListenerRegistrations,
            UnresolvedReason::UNREADABLE_MODEL_HOOK => self::ModelHooks,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::DispatchTargets => 'Dispatch targets',
            self::ClassLookup => 'Class lookup',
            self::ListenerRegistrations => 'Listener registrations',
            self::ModelHooks => 'Model hooks',
        };
    }

    public function intro(): string
    {
        return match ($this) {
            self::DispatchTargets => 'The call is recognised as a dispatch, but the event, job, mailable or notification it names is not a class Loom can read at that line.',
            self::ClassLookup => 'The class is named, but the index has nothing to link to. A dispatch gets one of these, never both.',
            self::ListenerRegistrations => 'The registration is recognised, but its event or handler cannot be read from the source.',
            self::ModelHooks => 'The hook is recognised, but its callback or model cannot be read.',
        };
    }
}
