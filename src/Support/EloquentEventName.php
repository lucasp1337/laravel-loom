<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;
use Lucasp\Loom\Index\ModelHook;

/**
 * The `eloquent.{hook}: {Model}` event-name format Eloquent fires for model
 * lifecycle events.
 *
 * @internal
 */
final class EloquentEventName
{
    private const string PREFIX = 'eloquent.';

    /** True for any `eloquent.`-prefixed name, whether or not it parses. */
    public static function is(string $event): bool
    {
        return Str::startsWith($event, self::PREFIX);
    }

    public static function make(ModelHook $hook, string $model): string
    {
        return self::PREFIX.$hook->value.': '.$model;
    }

    /**
     * @return array{hook: ModelHook, model: string}|null
     */
    public static function parse(string $event): ?array
    {
        if (! preg_match('/^eloquent\.([a-zA-Z]+):\s*(.+)$/', $event, $matches)) {
            return null;
        }

        $hook = ModelHook::tryFrom($matches[1]);
        $model = Fqcn::normalize(trim($matches[2]));
        if ($hook === null || $model === '') {
            return null;
        }

        return ['hook' => $hook, 'model' => $model];
    }
}
