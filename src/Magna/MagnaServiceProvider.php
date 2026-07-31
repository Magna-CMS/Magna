<?php

declare(strict_types=1);

namespace Magna;

use Illuminate\Support\ServiceProvider;
use Magna\AccountCentre\AccountCentreServiceProvider;
use Magna\Admin\AdminServiceProvider;
use Magna\Audit\AuditServiceProvider;
use Magna\Auth\AuthServiceProvider;
use Magna\Backup\BackupServiceProvider;
use Magna\Blocks\BlocksServiceProvider;
use Magna\Content\ContentServiceProvider;
use Magna\Delivery\DeliveryServiceProvider;
use Magna\Install\InstallServiceProvider;
use Magna\Licensing\LicensingServiceProvider;
use Magna\Management\ManagementServiceProvider;
use Magna\Media\MediaServiceProvider;
use Magna\Notices\NoticesServiceProvider;
use Magna\Plugins\PluginsServiceProvider;
use Magna\Privacy\PrivacyServiceProvider;
use Magna\Settings\PerformanceServiceProvider;
use Magna\Settings\SettingsServiceProvider;
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
    public const VERSION = '1.3.2';

    public function register(): void
    {
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

    public function boot(): void
    {
        //
    }
}
