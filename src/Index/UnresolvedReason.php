<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a dispatch or a listener / model-hook registration could not be resolved; the
 * values of `unresolved_dispatches[].reason`. Ordered as the schema lists them: dispatch
 * target forms, then class lookup, then registrations.
 *
 * A reason names the code shape Loom saw, so each one has a single fix. The
 * schema description is built from {@see self::summary()} and a test keeps
 * the two equal.
 *
 * @internal
 */
enum UnresolvedReason: string
{
    /** The target is a variable, or `new` / `::dispatch()` on one. */
    case DYNAMIC_CLASS_NAME = 'dynamic_class_name';

    /** The target comes from `app()`, `resolve()` or `->make()`. */
    case CONTAINER_RESOLUTION = 'container_resolution';

    /** The target is built by concatenation or interpolation. */
    case STRING_CONCATENATION = 'string_concatenation';

    /** The target is a ternary or `match` whose branches cannot be evaluated. */
    case CONDITIONAL_DISPATCH = 'conditional_dispatch';

    /** A `Bus::chain` / `batch` list is built at runtime. */
    case DYNAMIC_LIST = 'dynamic_list';

    /** The event is a string literal such as `'service.installed'`, not a class. */
    case STRING_EVENT_NAME = 'string_event_name';

    /** A closure is dispatched as a job, so no class names it. */
    case CLOSURE_JOB = 'closure_job';

    /** A mail sent from a view name or array and a closure, so no mailable class exists. */
    case VIEW_MAIL = 'view_mail';

    /** The target is what a method, static or function call returns. */
    case CALL_RESULT = 'call_result';

    /** The target is read from a property, array element or constant. */
    case STORED_TARGET = 'stored_target';

    /** `self`, `static` or `parent` where the enclosing code has no class name to give. */
    case RELATIVE_CLASS_NAME = 'relative_class_name';

    /** Any other expression form; the residual after every case above. */
    case UNSUPPORTED_EXPRESSION = 'unsupported_expression';

    /** The class is named but no scanned file declares it. Exclusive with `TARGET_NOT_INDEXED`. */
    case CLASS_NOT_FOUND = 'class_not_found';

    /** A file declares the class but it has no section row. Exclusive with `CLASS_NOT_FOUND`. */
    case TARGET_NOT_INDEXED = 'target_not_indexed';

    /** A registration whose event key or name is not a class constant or a readable literal. */
    case UNREADABLE_EVENT_NAME = 'unreadable_event_name';

    /** `Event::listen(function ($e) {})` whose closure parameter gives no event class. */
    case UNTYPED_CLOSURE_LISTENER = 'untyped_closure_listener';

    /** A listener registration whose handler is not a class, tuple, readable string or closure. */
    case UNREADABLE_LISTENER = 'unreadable_listener';

    /** A subscriber registration that is not a class reference or `new`. */
    case UNREADABLE_SUBSCRIBER = 'unreadable_subscriber';

    /** A model hook whose callback is not a closure or readable callable. */
    case UNREADABLE_MODEL_HOOK = 'unreadable_model_hook';

    /** One sentence on the code shape, the text of the schema description line. */
    public function summary(): string
    {
        return match ($this) {
            self::DYNAMIC_CLASS_NAME => 'the target is a variable, or `new $class` / `$class::dispatch()`.',
            self::CONTAINER_RESOLUTION => 'the target comes from `app()`, `resolve()` or `->make()`.',
            self::STRING_CONCATENATION => 'the target is built by concatenation or interpolation.',
            self::CONDITIONAL_DISPATCH => 'a ternary or `match` whose branches cannot be evaluated.',
            self::DYNAMIC_LIST => 'a list of dispatch targets built at runtime (a variable, a spread, a collection).',
            self::STRING_EVENT_NAME => 'the event is a string literal such as `\'service.installed\'`, not a class.',
            self::CLOSURE_JOB => 'a closure is dispatched as a job, so no class names it.',
            self::VIEW_MAIL => 'a mail sent from a view name or array and a closure, so there is no mailable class.',
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
