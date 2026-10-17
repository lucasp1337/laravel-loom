<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Source of truth for the `Route` facade router methods that declare routes,
 * and how each maps to emitted routes.
 *
 * Verbs map to one HTTP verb (`any` to `ANY`, `match` to its literal list);
 * the resource family expands through the resource registrar; `view`,
 * `redirect`, `permanentRedirect` and `fallback` register a route with no
 * controller of its own.
 *
 * @internal
 */
enum RouterMethod: string
{
    case GET = 'get';
    case POST = 'post';
    case PUT = 'put';
    case PATCH = 'patch';
    case DELETE = 'delete';
    case OPTIONS = 'options';
    case QUERY = 'query';
    case ANY = 'any';
    case MATCH = 'match';
    case RESOURCE = 'resource';
    case API_RESOURCE = 'apiResource';
    case SINGLETON = 'singleton';
    case API_SINGLETON = 'apiSingleton';
    case RESOURCES = 'resources';
    case API_RESOURCES = 'apiResources';
    case SOFT_DELETABLE_RESOURCES = 'softDeletableResources';
    case SINGLETONS = 'singletons';
    case API_SINGLETONS = 'apiSingletons';
    case VIEW = 'view';
    case REDIRECT = 'redirect';
    case PERMANENT_REDIRECT = 'permanentRedirect';
    case FALLBACK = 'fallback';

    /** Verbs a route entry may carry (`match` verbs outside this set are dropped). */
    public const EMITTED_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'QUERY'];

    /** The single HTTP verb of a verb method, or null for every other method. */
    public function verb(): ?string
    {
        return match ($this) {
            // Route::get() ... Route::query(): the method name is the verb
            self::GET, self::POST, self::PUT, self::PATCH, self::DELETE, self::OPTIONS, self::QUERY => strtoupper($this->value),
            // Route::any(): every verb, reported as ANY
            self::ANY => 'ANY',
            default => null,
        };
    }

    /**
     * The resource kind an expanding method registers, or null for a method
     * that is not part of the resource family.
     */
    public function resourceKind(): ?ResourceKind
    {
        return match ($this) {
            self::RESOURCE, self::RESOURCES, self::SOFT_DELETABLE_RESOURCES => ResourceKind::RESOURCE,
            self::API_RESOURCE, self::API_RESOURCES => ResourceKind::API_RESOURCE,
            self::SINGLETON, self::SINGLETONS => ResourceKind::SINGLETON,
            self::API_SINGLETON, self::API_SINGLETONS => ResourceKind::API_SINGLETON,
            default => null,
        };
    }

    /** True for the array forms (`resources([...])`) that register several resources at once. */
    public function isBatch(): bool
    {
        return match ($this) {
            self::RESOURCES, self::API_RESOURCES, self::SOFT_DELETABLE_RESOURCES, self::SINGLETONS, self::API_SINGLETONS => true,
            default => false,
        };
    }

    /**
     * Whether `RouteRegistrar` forwards this method, i.e. whether it may follow
     * attribute setters such as `Route::middleware('auth')->get(...)`. The
     * registrar passes through the verbs, `match` and the four single-resource
     * methods; `view`, `redirect`, `fallback` and the array forms exist only on
     * the router itself.
     */
    public function followsAttributes(): bool
    {
        return match ($this) {
            self::GET, self::POST, self::PUT, self::PATCH, self::DELETE, self::OPTIONS, self::ANY, self::MATCH,
            self::RESOURCE, self::API_RESOURCE, self::SINGLETON, self::API_SINGLETON => true,
            // the router's own helpers and the batch forms
            default => false,
        };
    }
}
