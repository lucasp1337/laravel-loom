<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Source of truth for the resource controller actions Laravel registers for
 * `Route::resource(...)` / `apiResource(...)` / `singleton(...)`, with each
 * action's HTTP verbs, URI suffix and whether it addresses a member.
 *
 * `update` is registered for both `PUT` and `PATCH`.
 *
 * @internal
 */
enum ResourceAction: string
{
    case Index = 'index';
    case Create = 'create';
    case Store = 'store';
    case Show = 'show';
    case Edit = 'edit';
    case Update = 'update';
    case Destroy = 'destroy';

    /**
     * The HTTP verbs the action is registered for.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return match ($this) {
            self::Index, self::Create, self::Show, self::Edit => ['GET'],
            self::Store => ['POST'],
            self::Update => ['PUT', 'PATCH'],
            self::Destroy => ['DELETE'],
        };
    }

    /** Whether the action's URI carries the member wildcard of a (non-singleton) resource. */
    public function isMember(): bool
    {
        return match ($this) {
            self::Show, self::Edit, self::Update, self::Destroy => true,
            self::Index, self::Create, self::Store => false,
        };
    }

    /** The literal segment appended after the base URI (and member wildcard), '' for none. */
    public function segment(): string
    {
        return match ($this) {
            self::Create => 'create',
            self::Edit => 'edit',
            self::Index, self::Store, self::Show, self::Update, self::Destroy => '',
        };
    }

    /**
     * Whether `shallow()` drops the parent segments from this action's URI and
     * name (the member actions that are not nested under their parents).
     */
    public function isShallowable(): bool
    {
        return match ($this) {
            self::Show, self::Edit, self::Update, self::Destroy => true,
            self::Index, self::Create, self::Store => false,
        };
    }

    /**
     * The full `resource` action set, in Laravel registration order.
     *
     * @return list<self>
     */
    public static function fullSet(): array
    {
        return [self::Index, self::Create, self::Store, self::Show, self::Edit, self::Update, self::Destroy];
    }

    /**
     * The `apiResource` action set (no `create` / `edit` HTML forms).
     *
     * @return list<self>
     */
    public static function apiSet(): array
    {
        return [self::Index, self::Store, self::Show, self::Update, self::Destroy];
    }

    /**
     * The singleton action set; `creatable()` adds create / store / destroy,
     * `destroyable()` adds destroy.
     *
     * @return list<self>
     */
    public static function singletonSet(bool $creatable, bool $destroyable): array
    {
        $set = [self::Show, self::Edit, self::Update];

        // creatable() / destroyable(): the create-side actions
        if ($creatable) {
            return [...$set, self::Create, self::Store, self::Destroy];
        }

        return $destroyable ? [...$set, self::Destroy] : $set;
    }
}
