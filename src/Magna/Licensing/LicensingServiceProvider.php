<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Magna\Licensing\Console\VerifyLicensesCommand;
use Throwable;

/**
 * Site-side licensing — core, never a plugin, for the same reason the
 * updater is core: the component that keeps paid products working (and
 * honest) must not be disableable from the plugin UI.
 *
 * The daily heartbeat is what makes enforcement meaningful. A site that
 * checks in every day only falls back on grace during a real outage, and a
 * cancelled corporate licence reaches that site within one cycle — after
 * which LicenseGate stops the plugin from booting at all.
 */
class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseStore::class);
        $this->app->singleton(LicenseClient::class);
        $this->app->singleton(LicenseGate::class);
        $this->app->singleton(LicenseGuard::class);
        $this->app->singleton(LicenseReactivator::class);
        $this->app->singleton(LicenseEnforcer::class);
        $this->app->singleton(PackageExtractor::class);
        $this->app->singleton(LicenseInstaller::class);
    }

    public function boot(): void
    {
        Route::middleware('web')->group(__DIR__.'/routes/web.php');

        $this->loadViewsFrom(__DIR__.'/resources/views', 'magna-licensing');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyLicensesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Offset from the updater's 12-hourly check so a site doesn't
            // fire every outbound call to the marketplace at the same minute.
            $schedule->command('magna:licensing:verify')
                ->cron('20 3 * * *')
                ->withoutOverlapping();
        });

        $this->registerAdminBanner();
    }

    /**
     * A locked product must say WHY on screen, not just vanish from the
     * plugin list. The banner reads cached state only (LicenseGate), so it
     * costs nothing per request and works during an outage.
     */
    private function registerAdminBanner(): void
    {
        if (! class_exists(FilamentView::class)) {
            return;
        }

        try {
            FilamentView::registerRenderHook(
                PanelsRenderHook::CONTENT_START,
                static function (): string {
                    $locked = app(LicenseGate::class)->locked();

                    if ($locked === []) {
                        return '';
                    }

                    return Blade::render(
                        '@include("magna-licensing::banner", ["locked" => $locked])',
                        ['locked' => $locked],
                    );
                },
            );
        } catch (Throwable) {
            // A panel that isn't booted (console, tests) simply has no hook
            // to register against — never fatal.
        }
    }
}
