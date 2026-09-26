<?php

declare(strict_types=1);

namespace Magna;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Magna\AccountCentre\AccountCentreServiceProvider;
use Magna\Admin\AdminServiceProvider;
use Magna\Audit\AuditServiceProvider;
use Magna\Auth\AuthServiceProvider;
use Magna\Backup\BackupServiceProvider;
use Magna\Blocks\BlocksServiceProvider;
use Magna\Content\ContentServiceProvider;
use Magna\Delivery\DeliveryServiceProvider;
use Magna\Install\EnvWriter;
use Magna\Install\InstallServiceProvider;
use Magna\Licensing\LicensingServiceProvider;
use Magna\Management\ManagementServiceProvider;
use Magna\Media\MediaServiceProvider;
use Magna\Notices\NoticesServiceProvider;
use Magna\Plugins\PluginsServiceProvider;
use Magna\Privacy\PrivacyServiceProvider;
use Magna\Settings\PerformanceServiceProvider;
use Magna\Settings\SettingsServiceProvider;
use Magna\Support\ComposerLoader;
use Magna\Support\ConfigDefaults;
use Magna\Support\DebugWindow;
use Magna\Support\StaleClassMap;
use Magna\Updater\UpdaterServiceProvider;
use Magna\Webhooks\WebhookServiceProvider;

/**
 * Root service provider for the Magna kernel.
 *
 * Kernel subsystems (auth, RBAC, plugins, content engine) register their own
 * providers here as they are built, stage by stage — see docs/build-plan.md.
 */
class MagnaServiceProvider extends ServiceProvider
{
    public const VERSION = '1.4.5';

    public function register(): void
    {
        /*
         * First, before anything reads config.
         *
         * An update overwrites `src/Magna` and leaves `config/` alone, so a key
         * a release adds never reaches an already-installed site — see
         * ConfigDefaults, which explains why that trade is right and what it
         * has cost. Safe here: the singleton below reads config inside its
         * factory closure, which is not resolved until boot().
         */
        $this->backfillCoreDefaults();

        // Right after, and before anything can ask class_exists() of a name a
        // release deleted: Filament's discovery, plugin boot, cached panel
        // component lists all do.
        $this->neutraliseStaleClassMap();

        $this->app->singleton(DebugWindow::class, function (): DebugWindow {
            return new DebugWindow(
                new EnvWriter(config()->string('magna.install.env_path', base_path('.env'))),
                config()->string('magna.debug_window.stamp_path', storage_path('app/magna-debug-window.json')),
            );
        });

        $this->app->register(SettingsServiceProvider::class);
        $this->app->register(PerformanceServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);
        $this->app->register(InstallServiceProvider::class);
        $this->app->register(AuditServiceProvider::class);
        $this->app->register(BackupServiceProvider::class);
        $this->app->register(ContentServiceProvider::class);
        $this->app->register(BlocksServiceProvider::class);
        $this->app->register(MediaServiceProvider::class);
        $this->app->register(DeliveryServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->app->register(UpdaterServiceProvider::class);
        $this->app->register(AccountCentreServiceProvider::class);
        // After Account Centre: licensing reads the account connection this
        // site holds, and is core for the same reason the updater is — the
        // component keeping paid products working can't be disableable.
        $this->app->register(LicensingServiceProvider::class);
        $this->app->register(NoticesServiceProvider::class);
        $this->app->register(WebhookServiceProvider::class);
        $this->app->register(ManagementServiceProvider::class);
        $this->app->register(PrivacyServiceProvider::class);
        $this->app->register(AdminServiceProvider::class);
    }

    /**
     * Core's own config, for the keys a site's file has never heard of.
     *
     * The two files Magna authors and ships. The rest of `config/` is
     * Laravel's, where the framework already answers for anything absent and a
     * shadow copy here would put Magna in the business of tracking framework
     * defaults forever — a shadow that fell behind would be this same bug with
     * a wider blast radius.
     *
     * `trustedproxy` is here for a reason worth stating: the file was ADDED in
     * 1.4.1, so a site that updated from 1.4.0 or earlier has no such file at
     * all. `config('trustedproxy.proxies')` is null there, TrustProxies trusts
     * nothing, and TRUSTED_PROXIES does nothing — the real client IP is lost
     * from the audit log and from login throttling, and isSecure() reads false
     * behind a proxy. Same delivery hole, same release, third instance. A
     * namespace backfill reaches it precisely because it does not need the
     * file to exist.
     *
     * `cors.php` is not here: it has shipped since the first release, so no
     * install is missing it, and every value in it is a list — the one shape
     * the backfill deliberately never touches.
     *
     * The defaults live beside this provider, in `src/Magna/Config/defaults`,
     * because `src/Magna` is the one path every core updater ever shipped has
     * overlaid. They used to live in `config/defaults`, which 1.4.3 added to
     * the updater's path list — and a release cannot deliver a path it adds
     * to that list, because the overlay is performed by the OLD release's
     * code. Every site that updated into 1.4.3 ran new code beside no
     * defaults, with every key null, while reporting itself up to date.
     * `config/defaults/*.php` remain as forwarders for that generation.
     * Larastan's env() rule is told about the new directory in
     * phpstan.neon.dist (`configDirectories`).
     *
     * Deliberately NOT guarded by `configurationIsCached()`, which is what both
     * of Laravel's merge helpers do. `config:cache` clears the cache and boots
     * a fresh application to read it, so this runs and its result is baked in —
     * the guard would only matter for a cache file written before an update,
     * and that is precisely the stale install this exists for.
     */
    private function backfillCoreDefaults(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        foreach (['magna', 'trustedproxy'] as $file) {
            $path = self::coreDefaultsPath($file);

            // Never fatal on a missing core file: see config/magna.php. A site
            // that boots wrong can be fixed from its own panel; one that does
            // not boot cannot.
            if (! is_file($path)) {
                continue;
            }

            /** @var array<string, mixed> $defaults */
            $defaults = require $path;

            ConfigDefaults::backfill($config, $file, $defaults);
        }
    }

    /**
     * Where core's own copy of a config file lives: inside `src/Magna`, so it
     * travels with the code on every update. Public so the tests that guard
     * the delivery path assert against the same location the boot reads.
     */
    public static function coreDefaultsPath(string $file): string
    {
        return __DIR__.'/Config/defaults/'.$file.'.php';
    }

    /**
     * A core update replaces `src/Magna` and never `vendor/`, so the site's
     * classmap keeps naming files a release deleted, and Composer includes
     * them unchecked — a 500 for any class_exists() on an old name. See
     * StaleClassMap. Kept as a singleton so System Info can report what was
     * disarmed.
     */
    private function neutraliseStaleClassMap(): void
    {
        $stale = new StaleClassMap(
            $this->app->storagePath('framework/cache/magna-stale-classmap.json'),
            $this->app->basePath('vendor/composer/autoload_classmap.php'),
            self::VERSION,
        );

        $stale->neutralise(ComposerLoader::instance());

        $this->app->instance(StaleClassMap::class, $stale);
    }

    public function boot(): void
    {
        // Close a panel-opened debug window that has run out. Costs one stat
        // call on a site where nobody opened one, and needs neither cron nor a
        // queue worker: whoever asks for the next page closes it. A flag an
        // operator set by hand at the shell has no stamp and is left alone.
        if (! $this->app->runningUnitTests()) {
            $this->app->make(DebugWindow::class)->enforce();
        }

        // Shared hosts rarely run a supervised queue worker, but they do run
        // cron — the same cron that already drives this scheduler. Drain the
        // queue from it: once a minute, consume everything pending and exit.
        // With a real worker these ticks find nothing and cost nothing; with
        // the sync driver nothing is ever queued; without any cron at all,
        // System Info's backlog warning still tells the operator the truth.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('queue.default') === 'sync') {
                return;
            }

            $schedule->command('queue:work', ['--stop-when-empty', '--max-time=55', '--tries=3'])
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
