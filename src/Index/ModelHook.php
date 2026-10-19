<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Eloquent lifecycle event names. Every case can appear in an
 * `eloquent.{hook}: {Model}` event string; only the observable ones are
 * wired to an observer's methods.
 *
 * @internal
 */
enum ModelHook: string
{
    case RETRIEVED = 'retrieved';
    case CREATING = 'creating';
    case CREATED = 'created';
    case UPDATING = 'updating';
    case UPDATED = 'updated';
    case SAVING = 'saving';
    case SAVED = 'saved';
    case DELETING = 'deleting';
    case DELETED = 'deleted';
    case RESTORING = 'restoring';
    case RESTORED = 'restored';
    case REPLICATING = 'replicating';
    case TRASHED = 'trashed';
    case FORCE_DELETING = 'forceDeleting';
    case FORCE_DELETED = 'forceDeleted';
    case BOOTING = 'booting';
    case BOOTED = 'booted';

    /**
     * Whether an observer method of this name is registered. `booting` and
     * `booted` are fired but are not in the model's observable-event list.
     */
    public function isObservable(): bool
    {
        return $this !== self::BOOTING && $this !== self::BOOTED;
    }

    /**
     * The hook a static registrar method (`Model::creating($callback)`,
     * `Model::softDeleted($callback)`) subscribes to; null when the method is
     * not one. `booting` and `booted` have no registrar, and `softDeleted`
     * registers `trashed`.
     */
    public static function fromRegistrar(string $method): ?self
    {
        $hook = match ($method) {
            // SoftDeletes names its `trashed` registrar after the soft delete.
            'softDeleted' => self::TRASHED,
            // `trashed()` is an instance check, not a registrar.
            'trashed' => null,
            default => self::tryFrom($method),
        };

        return $hook?->isObservable() === true ? $hook : null;
    }

    /** The static registrar method that subscribes to this hook, the inverse of {@see self::fromRegistrar()}. */
    public function registrar(): string
    {
        return match ($this) {
            // SoftDeletes names its `trashed` registrar after the soft delete.
            self::TRASHED => 'softDeleted',
            default => $this->value,
        };
    }

    /** @return list<string> */
    public static function observableValues(): array
    {
        $values = [];
        foreach (self::cases() as $hook) {
            if ($hook->isObservable()) {
                $values[] = $hook->value;
            }
        }

        return $values;
    }
}
