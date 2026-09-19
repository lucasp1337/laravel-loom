<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Query\SnapshotIndexSource;
use Lucasp\Loom\Support\IndexPath;
use Lucasp\Loom\Ui\Http\AssetController;
use Lucasp\Loom\Ui\Http\Middleware\AuthorizeLoom;
use Lucasp\Loom\Ui\Http\Middleware\RequireIndex;
use Lucasp\Loom\Ui\Http\ShellComposer;
use Lucasp\Loom\Ui\Livewire\ChainPage;
use Lucasp\Loom\Ui\Livewire\Dashboard;
use Lucasp\Loom\Ui\Livewire\EntityDetail;
use Lucasp\Loom\Ui\Livewire\EventDetail;
use Lucasp\Loom\Ui\Livewire\Palette;
use Lucasp\Loom\Ui\Livewire\SectionIndex;
use Lucasp\Loom\Ui\Support\AppChangeClock;
use Lucasp\Loom\Ui\Support\GitAppChangeClock;

/**
 * Wires the browser UI. Registered by the main provider; the UI is removable
 * by dropping that one call.
 *
 * @internal
 */
final class LoomUiServiceProvider extends ServiceProvider
{
    private const VIEWS = 'loom';

    public function register(): void
    {
        $this->app->singleton(LoomConfig::class, fn ($app): LoomConfig => new LoomConfig($app->make('config')));

        // The UI reads the snapshot only; unlike the MCP server it never scans.
        $this->app->singleton(UiContext::class, fn ($app): UiContext => new UiContext(new SnapshotIndexSource(
            $app->make(IndexLoader::class),
            $app->make(IndexPath::class)->resolve(),
        )));
        $this->app->singleton(AppChangeClock::class, fn ($app): AppChangeClock => new GitAppChangeClock($app->basePath()));
    }

    public function boot(): void
    {
        $config = $this->app->make(LoomConfig::class);

        $this->publishes([
            dirname(__DIR__, 2).'/config/loom.php' => $this->app->configPath('loom.php'),
        ], 'loom-config');

        $environment = $this->app->environment();

        if ($config->blockedProduction($environment)) {
            Log::warning('Loom UI not mounted: `production` is listed in loom.ui.environments but loom.ui.allow_in_production is not true.');
        }

        if (! $config->servesIn($environment)) {
            return;
        }

        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', self::VIEWS);
        Blade::anonymousComponentPath(dirname(__DIR__, 2).'/resources/views/components', self::VIEWS);
        View::composer('loom::layouts.app', ShellComposer::class);

        $this->defineGate();
        $this->registerComponents();
        $this->registerRoutes($config);
    }

    /** Local-only by default; an app's own `Gate::define('viewLoom', ...)` wins in either provider order. */
    private function defineGate(): void
    {
        if (! Gate::has(LoomAbility::VIEW->value)) {
            Gate::define(
                LoomAbility::VIEW->value,
                fn (?Authenticatable $user = null): bool => $this->app->environment('local'),
            );
        }
    }

    private function registerComponents(): void
    {
        Livewire::component('loom.dashboard', Dashboard::class);
        Livewire::component('loom.section-index', SectionIndex::class);
        Livewire::component('loom.entity-detail', EntityDetail::class);
        Livewire::component('loom.event-detail', EventDetail::class);
        Livewire::component('loom.chain-page', ChainPage::class);
        Livewire::component('loom.palette', Palette::class);

        // Livewire's update endpoint is app-wide; re-run the gate on every update.
        Livewire::addPersistentMiddleware([AuthorizeLoom::class]);
    }

    private function registerRoutes(LoomConfig $config): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $group = ['prefix' => $config->path(), ...($config->domain() === null ? [] : ['domain' => $config->domain()])];

        // Static files stay outside the gate so the 403 page can still load its CSS.
        Route::group($group, function (): void {
            Route::get('assets/{file}', AssetController::class)->name('loom.assets');
        });

        Route::group([...$group, 'middleware' => [...$config->middleware(), AuthorizeLoom::class, RequireIndex::class]], function (): void {
            $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/loom.php');
        });
    }
}
