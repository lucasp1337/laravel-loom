<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The four resource registrations of the router and the action set each
 * starts from, before `only` / `except` narrow it.
 *
 * @internal
 */
enum ResourceKind
{
    case RESOURCE;
    case API_RESOURCE;
    case SINGLETON;
    case API_SINGLETON;

    public function isSingleton(): bool
    {
        return match ($this) {
            self::SINGLETON, self::API_SINGLETON => true,
            self::RESOURCE, self::API_RESOURCE => false,
        };
    }

    /**
     * Actions the kind registers by default, in registration order.
     *
     * @return list<ResourceAction>
     */
    public function defaultActions(bool $creatable, bool $destroyable): array
    {
        return match ($this) {
            self::RESOURCE, self::API_RESOURCE => ResourceAction::fullSet(),
            self::SINGLETON, self::API_SINGLETON => ResourceAction::singletonSet($creatable, $destroyable),
        };
    }

    /**
     * The `only` list the API variants start with; null for the plain kinds.
     *
     * @return list<ResourceAction>|null
     */
    public function apiOnly(): ?array
    {
        return match ($this) {
            self::API_RESOURCE => ResourceAction::apiSet(),
            self::API_SINGLETON => [ResourceAction::Store, ResourceAction::Show, ResourceAction::Update, ResourceAction::Destroy],
            self::RESOURCE, self::SINGLETON => null,
        };
    }
}
