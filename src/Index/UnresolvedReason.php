<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a dispatch or a listener / model-hook registration could not be resolved; the
 * values of `unresolved_dispatches[].reason`. Ordered as the schema lists them: dispatch
 * target forms, then class lookup, then registrations.
 *
 * A reason names the code shape Loom saw, so each one has a single fix; the
 * schema reference lists each with {@see self::summary()}.
 *
 * @internal
 */
enum UnresolvedReason: string implements Summarized
{
    case DYNAMIC_CLASS_NAME = 'dynamic_class_name';
    case DYNAMIC_METHOD_NAME = 'dynamic_method_name';
    case CONTAINER_RESOLUTION = 'container_resolution';
    case STRING_CONCATENATION = 'string_concatenation';
    case CONDITIONAL_DISPATCH = 'conditional_dispatch';
    case DYNAMIC_LIST = 'dynamic_list';
    case STRING_EVENT_NAME = 'string_event_name';
    case CLOSURE_JOB = 'closure_job';
    case VIEW_MAIL = 'view_mail';
    case CALL_RESULT = 'call_result';
    case STORED_TARGET = 'stored_target';
    case RELATIVE_CLASS_NAME = 'relative_class_name';
    case UNSUPPORTED_EXPRESSION = 'unsupported_expression';
    case CLASS_NOT_FOUND = 'class_not_found';
    case TARGET_NOT_INDEXED = 'target_not_indexed';
    case UNREADABLE_EVENT_NAME = 'unreadable_event_name';
    case UNTYPED_CLOSURE_LISTENER = 'untyped_closure_listener';
    case UNREADABLE_LISTENER = 'unreadable_listener';
    case UNREADABLE_SUBSCRIBER = 'unreadable_subscriber';
    case UNREADABLE_MODEL_HOOK = 'unreadable_model_hook';

    public function summary(): string
    {
        return match ($this) {
            self::DYNAMIC_CLASS_NAME => 'the target is a variable, or `new $class` / `$class::dispatch()`.',
            self::DYNAMIC_METHOD_NAME => 'a dispatching facade called with a variable method name (`Bus::$method($job)`), so the call it makes is unknown.',
            self::CONTAINER_RESOLUTION => 'the target comes from `app()`, `resolve()` or `->make()`.',
            self::STRING_CONCATENATION => 'the target is built by concatenation or interpolation.',
            self::CONDITIONAL_DISPATCH => 'a ternary or `match` whose branches cannot be evaluated.',
            self::DYNAMIC_LIST => 'a list of dispatch targets built at runtime (a variable, a spread, a collection).',
            self::STRING_EVENT_NAME => 'the event is a string literal such as `\'service.installed\'`, not a class.',
            self::CLOSURE_JOB => 'a closure is dispatched as a job, so no class names it.',
            self::VIEW_MAIL => 'a mail built from a view, an array, raw text or HTML (`Mail::send(\'view\', ...)`, `Mail::raw()`), so there is no mailable class.',
            self::CALL_RESULT => 'the target is what a method, static or function call returns.',
            self::STORED_TARGET => 'the target is read from a property, an array element or a constant.',
            self::RELATIVE_CLASS_NAME => '`self`, `static` or `parent` in a trait or anonymous class, or `parent` with no parent: there is no class name to read.',
            self::UNSUPPORTED_EXPRESSION => 'an expression form no other reason covers (a `??` coalesce, an anonymous class).',
            self::CLASS_NOT_FOUND => 'the target is a class name, but no file declaring it exists in the scanned directories (a vendor class, a missing file, an excluded path); `expression` is `Fqcn::class`.',
            self::TARGET_NOT_INDEXED => 'a class file was found, but it is not an indexed event, job, mailable or notification.',
            self::UNREADABLE_EVENT_NAME => 'a registration whose event name or key is not a `::class` reference or a literal Loom can read (a variable, a call, an `eloquent.*` name with an unknown hook).',
            self::UNTYPED_CLOSURE_LISTENER => 'an `Event::listen(function ($e) {...})` closure whose first parameter has no event class type.',
            self::UNREADABLE_LISTENER => 'a listener registration (`$listen`, `Event::listen()`, a subscriber return array) whose handler is not a class reference, a `[Class, method]` tuple, a readable string or a closure.',
            self::UNREADABLE_SUBSCRIBER => 'an `Event::subscribe()` call or `$subscribe` entry that is not a `::class` reference, a string literal or `new`.',
            self::UNREADABLE_MODEL_HOOK => 'a `static::creating(...)` style hook whose callback is not a closure or readable callable.',
        };
    }
}
