<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tests\Feature\Ui;

use Illuminate\Support\Facades\Gate;
use Lucasp\Loom\Ui\LoomAbility;

/**
 * Points the UI at the shared fixture index written to a temp snapshot, with
 * the gate opened. Laravel's test lifecycle calls the setUp/tearDown hooks by
 * trait name.
 */
trait UiSnapshot
{
    protected string $snapshot;

    protected function setUpUiSnapshot(): void
    {
        $this->snapshot = sys_get_temp_dir().'/loom-ui-'.bin2hex(random_bytes(6)).'.json';
        $this->writeSnapshot();
        config()->set('loom.ui.index_path', $this->snapshot);
        Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => true);
    }

    /** @param  array<string, mixed>  $config */
    public function bootUiAs(string $environment, array $config = [], bool $openGate = true): void
    {
        $this->loomEnvironment = $environment;
        $this->loomConfig = $config;
        $this->refreshApplication();
        config()->set('loom.ui.index_path', $this->snapshot);

        if ($openGate) {
            Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => true);
        }
    }

    protected function tearDownUiSnapshot(): void
    {
        @unlink($this->snapshot);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function writeSnapshot(array $overrides = []): void
    {
        /** @var array<string, mixed> $data */
        $data = array_merge(require __DIR__.'/../../Fixtures/query-index.php', $overrides);
        file_put_contents($this->snapshot, json_encode($data));
    }
}
