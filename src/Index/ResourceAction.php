<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Source of truth for the resource controller actions Laravel registers for
 * `Route::resource(...)` / `Route::apiResource(...)`, with each action's HTTP
 * verb, uri suffix, and whether it addresses a member (takes the parameter).
 *
 * The `{param}` placeholder in a suffix is substituted with the singularised
 * resource name by the scanner. `update` emits PUT only (Laravel also routes
 * PATCH to the same action).
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

    /** The HTTP verb Loom emits for this action. */
    public function method(): string
    {
        return match ($this) {
            self::Index, self::Create, self::Show, self::Edit => 'GET',
            self::Store => 'POST',
            self::Update => 'PUT',
            self::Destroy => 'DELETE',
        };
    }

    /** The uri suffix appended to the resource name ('{param}' = member placeholder). */
    public function suffix(): string
    {
        return match ($this) {
            self::Index, self::Store => '',
            self::Create => '/create',
            self::Show, self::Update, self::Destroy => '/{param}',
            self::Edit => '/{param}/edit',
        };
    }

    /**
     * The full `resource` action set, in Laravel registration order.
     *
     * @return list<self>
     */
    public static function fullSet(): array
    {
        return [
            self::Index,
            self::Create,
            self::Store,
            self::Show,
            self::Edit,
            self::Update,
            self::Destroy,
        ];
    }

    /**
     * The `apiResource` action set (no create/edit member-less HTML forms).
     *
     * @return list<self>
     */
    public static function apiSet(): array
    {
        return [
            self::Index,
            self::Store,
            self::Show,
            self::Update,
            self::Destroy,
        ];
    }
}
